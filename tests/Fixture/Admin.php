<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Tests\Fixture;

use MSSTC4PHP\ValueObject\Identity\EmailAddress;

class Admin extends Account
{
    public ?EmailAddress $backupEmail = null;
}
