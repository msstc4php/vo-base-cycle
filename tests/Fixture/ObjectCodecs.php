<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Tests\Fixture;

use MSSTC4PHP\ValueObject\Payload\ProvidesCodecs;
use Override;

/**
 * A package's codec set, as vo-documents ships one.
 */
final class ObjectCodecs implements ProvidesCodecs
{
    #[Override]
    public static function all(): array
    {
        return [new EmailAddressObjectCodec(), new PortObjectCodec()];
    }
}
