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
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use Override;

/**
 * Cycle ORM typecast handler for value objects and backed enums. List it before
 * Cycle's own Typecast in SchemaInterface::TYPECAST_HANDLER: it claims every
 * column whose rule is a class ValueObjectMapper supports and leaves the other
 * rules to the next handler.
 *
 * Cycle builds one handler per role through its container, passing the role by
 * name; a ValueObjectMapper bound in that container (custom codecs) is injected.
 * An instance placed in the schema directly must not be shared between roles.
 *
 * @phpstan-type Column array{class: class-string<ValueObject>|class-string<BackedEnum>, composite: bool}
 */
final class ValueObjectTypecast implements CastableInterface, UncastableInterface
{
    /**
     * @var array<non-empty-string, Column>
     */
    private array $columns = [];

    /**
     * @param non-empty-string $role the entity role, used in error messages
     * @param bool $untrustedCastInput the role's cast() also receives data that did not come from the
     *                                 database, such as request input given to ORM::make(..., typecast: true):
     *                                 every value is then read as untrusted, and a class that must not be
     *                                 built from untrusted input (BoundedString) is refused as a rule
     */
    public function __construct(
        private readonly string $role,
        private readonly ValueObjectMapper $mapper = new ValueObjectMapper(),
        private readonly bool $untrustedCastInput = false,
    ) {
    }

    /**
     * @param array<non-empty-string, mixed> $rules
     *
     * @return array<non-empty-string, mixed> the rules this handler did not claim
     *
     * @throws LogicException for a value-object class the mapper cannot store (no handler after this one would build
     *                        it, and Cycle drops unclaimed rules silently), or one refused for untrusted cast input
     */
    #[Override]
    public function setRules(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            $class = \is_string($rule) ? ltrim($rule, '\\') : null;
            if ($class === null || !is_subclass_of($class, ValueObject::class) && !is_subclass_of($class, BackedEnum::class)) {
                continue;
            }

            if (!$this->mapper->supports($class)) {
                throw new LogicException(\sprintf('The typecast rule of `%s.%s` is %s, which has no payload form; register a PayloadCodec for it.', $this->role, $key, $class));
            }

            if ($this->untrustedCastInput && !$this->mapper->supportsUntrusted($class)) {
                throw new LogicException(\sprintf('The typecast rule of `%s.%s` is %s, which cannot be read from untrusted input; this handler is configured for untrusted cast input.', $this->role, $key, $class));
            }

            $this->columns[$key] = ['class' => $class, 'composite' => $this->mapper->isComposite($class)];
            unset($rules[$key]);
        }

        return $rules;
    }

    /**
     * Trusted: the value is assumed to come from the database, unless the
     * handler was built with $untrustedCastInput.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws TypecastException when a value is not a valid value object of its column's class
     */
    #[Override]
    public function cast(array $data): array
    {
        foreach ($this->columns as $key => $column) {
            if (!isset($data[$key]) || $data[$key] instanceof $column['class']) {
                continue;
            }

            try {
                $data[$key] = $this->read($column, $data[$key], $this->untrustedCastInput);
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
     * the column; a raw BoundedString is refused, its payload carries its own bounds.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws TypecastException when a raw value is not a valid value object of its column's class or must not be
     *                           built from untrusted input, or a composite cannot be encoded as JSON
     */
    #[Override]
    public function uncast(array $data): array
    {
        foreach ($this->columns as $key => $column) {
            $value = $data[$key] ?? null;
            if ($value === null) {
                continue;
            }

            try {
                if (!$value instanceof $column['class']) {
                    $value = $this->read($column, $value, true);
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
     * @param Column $column
     *
     * @throws InvalidValueObjectException
     * @throws JsonException
     */
    private function read(array $column, mixed $raw, bool $untrusted): ValueObject|BackedEnum
    {
        // The trust refusal must win over a JSON error, whatever the raw value looks like.
        if ($untrusted && !$this->mapper->supportsUntrusted($column['class'])) {
            return $this->mapper->fromUntrustedPayload($column['class'], $raw);
        }

        $payload = $column['composite'] ? $this->jsonObject($column['class'], $raw) : $raw;

        return $untrusted
            ? $this->mapper->fromUntrustedPayload($column['class'], $payload)
            : $this->mapper->fromPayload($column['class'], $payload);
    }

    /**
     * A composite is stored as JSON text; a driver or caller may also hand over the decoded array.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidPayloadException
     * @throws JsonException
     */
    private function jsonObject(string $class, mixed $raw): array
    {
        $decoded = \is_string($raw) ? json_decode($raw, true, flags: \JSON_THROW_ON_ERROR) : $raw;

        return \is_array($decoded)
            ? $decoded
            : throw new InvalidPayloadException(\sprintf('A %s payload must be a JSON object, %s given.', $class, get_debug_type($decoded)));
    }

    private function failure(string $direction, string $key, InvalidValueObjectException|JsonException $e): TypecastException
    {
        return new TypecastException(\sprintf('Unable to %s the `%s.%s` field: %s', $direction, $this->role, $key, $e->getMessage()), 0, $e);
    }
}
