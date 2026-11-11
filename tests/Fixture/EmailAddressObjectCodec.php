<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Tests\Fixture;

use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Payload\PayloadCodec;
use Override;

/**
 * Turns a string-backed value object into a composite, which no built-in codec does.
 *
 * @implements PayloadCodec<EmailAddress>
 */
final readonly class EmailAddressObjectCodec implements PayloadCodec
{
    #[Override]
    public function valueObjectClass(): string
    {
        return EmailAddress::class;
    }

    /**
     * @return array{address: string}
     */
    #[Override]
    public function encode(ValueObject $value): array
    {
        return $value instanceof EmailAddress ? ['address' => (string) $value] : throw new LogicException('Not an email address.');
    }

    #[Override]
    public function decode(array $payload): EmailAddress
    {
        $address = $payload['address'] ?? null;

        return \is_string($address) ? EmailAddress::from($address) : throw new InvalidPayloadException('Expected {"address": string}.');
    }
}
