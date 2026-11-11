<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Typecast;

use BackedEnum;
use Cycle\ORM\Exception\TypecastException;
use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\UncastableInterface;
use JsonException;
use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Payload\PayloadCodec;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use Override;

/**
 * Cycle ORM typecast handler for value objects and backed enums. List it before
 * Cycle's own Typecast in SchemaInterface::TYPECAST_HANDLER: it claims every
 * column whose rule is a class ValueObjectMapper supports and leaves the other
 * rules to the next handler.
 *
 * Cycle builds one handler per role and passes the role by name; an instance
 * placed in the schema directly must therefore not be shared between roles.
 */
final class ValueObjectTypecast implements CastableInterface, UncastableInterface
{
    private readonly ValueObjectMapper $mapper;

    /**
     * @var array<non-empty-string, class-string<ValueObject>|class-string<BackedEnum>>
     */
    private array $rules = [];

    /**
     * @param non-empty-string $role the entity role, used in error messages
     * @param iterable<array-key, PayloadCodec<ValueObject>> $codecs added to the core's built-in codecs, as in ValueObjectMapper
     */
    public function __construct(
        private readonly string $role,
        iterable $codecs = [],
    ) {
        $this->mapper = new ValueObjectMapper($codecs);
    }

    /**
     * @param array<non-empty-string, mixed> $rules
     *
     * @return array<non-empty-string, mixed> the rules this handler did not claim
     *
     * @throws LogicException for a value-object class the mapper cannot store: no handler after this one would
     *                        build it, and Cycle drops unclaimed rules silently
     */
    #[Override]
    public function setRules(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            if (!\is_string($rule) || !is_subclass_of($rule, ValueObject::class) && !is_subclass_of($rule, BackedEnum::class)) {
                continue;
            }

            if (!$this->mapper->supports($rule)) {
                throw new LogicException(\sprintf('The typecast rule of `%s.%s` is %s, which has no payload form; register a PayloadCodec for it.', $this->role, $key, $rule));
            }

            $this->rules[$key] = $rule;
            unset($rules[$key]);
        }

        return $rules;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws TypecastException when a stored value is not a valid value object of its column's class
     */
    #[Override]
    public function cast(array $data): array
    {
        foreach ($this->rules as $key => $class) {
            if (!isset($data[$key]) || $data[$key] instanceof $class) {
                continue;
            }

            try {
                $data[$key] = $this->mapper->fromPayload($class, $this->decode($class, $data[$key]));
            } catch (InvalidValueObjectException|JsonException $e) {
                throw $this->failure('typecast', $key, $e);
            }
        }

        return $data;
    }

    /**
     * A value that is not yet an instance of the column's class (an untyped
     * property, or a parent role's handler running after a child's) is read as
     * untrusted input and written in canonical form, so nothing invalid reaches
     * the column.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws TypecastException when a raw value is not a valid value object of its column's class, or a composite
     *                           cannot be encoded as JSON
     * @throws LogicException when a raw value is given for a class that must not be built from untrusted input,
     *                        such as BoundedString, whose payload carries its own bounds
     */
    #[Override]
    public function uncast(array $data): array
    {
        foreach ($this->rules as $key => $class) {
            $value = $data[$key] ?? null;
            if ($value === null) {
                continue;
            }

            if (!$value instanceof $class && !$this->mapper->supportsUntrusted($class)) {
                throw new LogicException(\sprintf('The `%s.%s` field holds a %s, but %s is written only from an instance: its payload carries its own constraints.', $this->role, $key, get_debug_type($value), $class));
            }

            try {
                if (!$value instanceof $class) {
                    $value = $this->mapper->fromUntrustedPayload($class, $this->decode($class, $value));
                }

                $payload = $this->mapper->toPayload($value);
                $data[$key] = \is_array($payload) ? json_encode($payload, \JSON_THROW_ON_ERROR) : $payload;
            } catch (InvalidValueObjectException|JsonException $e) {
                throw $this->failure('uncast', $key, $e);
            }
        }

        return $data;
    }

    /**
     * A composite travels as JSON text; the scalar kinds and enums as they are.
     *
     * @param class-string<ValueObject>|class-string<BackedEnum> $class
     *
     * @throws JsonException
     */
    private function decode(string $class, mixed $raw): mixed
    {
        $composite = !$this->mapper->isScalarBacked($class) && !is_subclass_of($class, BackedEnum::class);

        return $composite && \is_string($raw) ? json_decode($raw, true, flags: \JSON_THROW_ON_ERROR) : $raw;
    }

    private function failure(string $direction, string $key, InvalidValueObjectException|JsonException $e): TypecastException
    {
        return new TypecastException(\sprintf('Unable to %s the `%s.%s` field: %s', $direction, $this->role, $key, $e->getMessage()), 0, $e);
    }
}
