<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Tests\Fixture;

use MSSTC4PHP\ValueObject\Geo\GeoCoordinates;
use MSSTC4PHP\ValueObject\I18n\CountryCode;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Network\Port;
use MSSTC4PHP\ValueObject\Primitive\BoundedString;
use MSSTC4PHP\ValueObject\Primitive\Percentage;
use MSSTC4PHP\ValueObject\Temporal\DateRange;

// Not final: Cycle's default Mapper hydrates through a generated subclass.
class Account
{
    public ?int $id = null;

    public ?EmailAddress $email = null;

    public ?Port $port = null;

    public ?Percentage $discount = null;

    public ?CountryCode $country = null;

    public ?Priority $priority = null;

    public ?GeoCoordinates $location = null;

    public ?DateRange $period = null;

    public ?BoundedString $title = null;

    public ?int $visits = null;
}
