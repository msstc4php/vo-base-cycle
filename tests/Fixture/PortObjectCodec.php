<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Tests\Fixture;

use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Network\Port;
use MSSTC4PHP\ValueObject\Payload\PayloadCodec;
use Override;

/**
 * @implements PayloadCodec<Port>
 */
final readonly class PortObjectCodec implements PayloadCodec
{
    #[Override]
    public function valueObjectClass(): string
    {
        return Port::class;
    }

    /**
     * @return array{number: int}
     */
    #[Override]
    public function encode(ValueObject $value): array
    {
        return $value instanceof Port ? ['number' => $value->jsonSerialize()] : throw new LogicException('Not a port.');
    }

    #[Override]
    public function decode(array $payload): Port
    {
        $number = $payload['number'] ?? null;

        return \is_int($number) ? Port::from($number) : throw new InvalidPayloadException('Expected {"number": int}.');
    }
}
