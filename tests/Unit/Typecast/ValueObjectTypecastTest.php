<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Tests\Unit\Typecast;

use Cycle\ORM\Exception\TypecastException;
use DateTimeImmutable;
use JsonException;
use LogicException;
use MSSTC4PHP\ValueObject\Cycle\Tests\Fixture\EmailAddressObjectCodec;
use MSSTC4PHP\ValueObject\Cycle\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Cycle\Typecast\ValueObjectTypecast;
use MSSTC4PHP\ValueObject\Exception\InvalidEmailAddressException;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Geo\GeoCoordinates;
use MSSTC4PHP\ValueObject\I18n\CountryCode;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Network\Port;
use MSSTC4PHP\ValueObject\Network\QueryParameter;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Primitive\BoundedString;
use MSSTC4PHP\ValueObject\Primitive\Percentage;
use MSSTC4PHP\ValueObject\Temporal\DateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(ValueObjectTypecast::class)]
final class ValueObjectTypecastTest extends TestCase
{
    private const array RULES = [
        'email' => EmailAddress::class,
        'port' => Port::class,
        'discount' => Percentage::class,
        'country' => CountryCode::class,
        'priority' => Priority::class,
        'location' => GeoCoordinates::class,
        'period' => DateRange::class,
        'title' => BoundedString::class,
    ];

    private const array RULES_WITHOUT_TITLE = [
        'email' => EmailAddress::class,
        'port' => Port::class,
        'discount' => Percentage::class,
        'country' => CountryCode::class,
        'priority' => Priority::class,
        'location' => GeoCoordinates::class,
        'period' => DateRange::class,
    ];

    public function testClaimsSupportedClassesAndReturnsTheRestInOrder(): void
    {
        $rest = new ValueObjectTypecast('account')->setRules([
            'visits' => 'int',
            'email' => EmailAddress::class,
            'createdAt' => 'datetime',
            'stamp' => DateTimeImmutable::class,
            'callback' => [self::class, 'provideStoredValues'],
            'priority' => Priority::class,
            'unknown' => 'No\\Such\\Class',
        ]);

        self::assertSame([
            'visits' => 'int',
            'createdAt' => 'datetime',
            'stamp' => DateTimeImmutable::class,
            'callback' => [self::class, 'provideStoredValues'],
            'unknown' => 'No\\Such\\Class',
        ], $rest);
    }

    public function testRejectsAValueObjectWithoutPayloadForm(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The typecast rule of `account.filter` is ' . QueryParameter::class . ', which has no payload form; register a PayloadCodec for it.');

        new ValueObjectTypecast('account')->setRules(['email' => EmailAddress::class, 'filter' => QueryParameter::class]);
    }

    /**
     * @return iterable<string, array{non-empty-string, mixed, object}>
     */
    public static function provideStoredValues(): iterable
    {
        yield 'string' => ['email', 'user@example.com', EmailAddress::from('user@example.com')];
        yield 'int' => ['port', 8080, Port::from(8080)];
        yield 'int from a PDO string' => ['port', '8080', Port::from(8080)];
        yield 'float' => ['discount', 12.5, Percentage::from(12.5)];
        yield 'float from a PDO string' => ['discount', '12.5', Percentage::from(12.5)];
        yield 'string enum' => ['country', 'DE', CountryCode::DE];
        yield 'int enum' => ['priority', 9, Priority::High];
        yield 'int enum from a PDO string' => ['priority', '9', Priority::High];
        yield 'composite from JSON' => ['location', '{"lat":52.52,"lng":13.405}', GeoCoordinates::fromFloats(52.52, 13.405)];
        yield 'composite from a decoded array' => ['location', ['lat' => 52.52, 'lng' => 13.405], GeoCoordinates::fromFloats(52.52, 13.405)];
        yield 'composite bounded string' => ['title', '{"value":"Hi","minLength":1,"maxLength":5}', new BoundedString('Hi', 1, 5)];
        yield 'composite date range' => [
            'period',
            '{"start":"2026-01-01T00:00:00.000000+00:00","end":"2026-12-31T23:59:59.999999+00:00"}',
            new DateRange(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-12-31T23:59:59.999999Z')),
        ];
    }

    /**
     * @param non-empty-string $field
     */
    #[DataProvider('provideStoredValues')]
    public function testCastsAStoredValue(string $field, mixed $stored, object $expected): void
    {
        $typecast = $this->typecast();

        $cast = $typecast->cast([$field => $stored, 'untouched' => 'raw']);

        self::assertSame(['untouched' => 'raw'], array_diff_key($cast, [$field => true]));
        self::assertEquals($expected, $cast[$field]);
        self::assertInstanceOf($expected::class, $cast[$field]);
    }

    /**
     * @param non-empty-string $field
     */
    #[DataProvider('provideStoredValues')]
    public function testUncastsTheCastValueBackToItsStoredForm(string $field, mixed $stored, object $expected): void
    {
        $typecast = $this->typecast();

        $payload = $typecast->uncast($typecast->cast([$field => $stored]))[$field];

        self::assertEquals($expected, $typecast->cast([$field => $payload])[$field]);
        self::assertTrue(\is_string($payload) || \is_int($payload) || \is_float($payload));
    }

    public function testUncastsEveryKindToItsStoredForm(): void
    {
        $uncast = $this->typecast()->uncast([
            'email' => EmailAddress::from('user@example.com'),
            'port' => Port::from(8080),
            'discount' => Percentage::from(12.5),
            'country' => CountryCode::DE,
            'priority' => Priority::High,
            'location' => GeoCoordinates::fromFloats(52.52, 13.405),
            'period' => new DateRange(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-12-31T23:59:59.999999Z')),
            'visits' => 3,
        ]);

        self::assertSame([
            'email' => 'user@example.com',
            'port' => 8080,
            'discount' => 12.5,
            'country' => 'DE',
            'priority' => 9,
            'location' => '{"lat":52.52,"lng":13.405}',
            'period' => '{"start":"2026-01-01T00:00:00.000000+00:00","end":"2026-12-31T23:59:59.999999+00:00"}',
            'visits' => 3,
        ], $uncast);
    }

    public function testNullAndMissingColumnsStayAsTheyAre(): void
    {
        $typecast = $this->typecast();
        $nulls = array_fill_keys(array_keys(self::RULES), null);

        self::assertSame($nulls, $typecast->cast($nulls));
        self::assertSame($nulls, $typecast->uncast($nulls));
        self::assertSame(['visits' => 1], $typecast->cast(['visits' => 1]));
        self::assertSame(['visits' => 1], $typecast->uncast(['visits' => 1]));
    }

    public function testAnAlreadyCastValueIsKept(): void
    {
        $email = EmailAddress::from('user@example.com');

        self::assertSame($email, $this->typecast()->cast(['email' => $email])['email']);
    }

    public function testUncastValidatesARawValueAndWritesItsCanonicalForm(): void
    {
        $uncast = $this->typecast()->uncast([
            'email' => 'user@example.com',
            'port' => '8080',
            'country' => 'DE',
            'location' => '{"lng":2,"lat":1}',
            'period' => ['start' => '2026-01-01T00:00:00.000000+00:00', 'end' => '2026-01-02T00:00:00.000000+00:00'],
        ]);

        self::assertSame([
            'email' => 'user@example.com',
            'port' => 8080,
            'country' => 'DE',
            'location' => '{"lat":1,"lng":2}',
            'period' => '{"start":"2026-01-01T00:00:00.000000+00:00","end":"2026-01-02T00:00:00.000000+00:00"}',
        ], $uncast);
    }

    /**
     * @return iterable<string, array{non-empty-string, mixed, class-string<Throwable>, non-empty-string}>
     */
    public static function provideInvalidRawValues(): iterable
    {
        yield 'invalid content' => ['email', 'not-an-email', InvalidEmailAddressException::class, 'Unable to uncast the `account.email` field: '];
        yield 'another value object' => ['port', EmailAddress::from('user@example.com'), InvalidPayloadException::class, 'Unable to uncast the `account.port` field: A ' . Port::class . ' payload must be an integer, ' . EmailAddress::class . ' given.'];
        yield 'malformed JSON' => ['location', '{"lat":', JsonException::class, 'Unable to uncast the `account.location` field: Syntax error'];
    }

    /**
     * @param non-empty-string $field
     * @param class-string<Throwable> $cause
     * @param non-empty-string $message
     */
    #[DataProvider('provideInvalidRawValues')]
    public function testAnInvalidRawValueIsNeverWritten(string $field, mixed $raw, string $cause, string $message): void
    {
        try {
            $this->typecast()->uncast([$field => $raw]);
            self::fail('No exception thrown.');
        } catch (TypecastException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
            self::assertInstanceOf($cause, $e->getPrevious());
            self::assertSame(0, $e->getCode());
        }
    }

    /**
     * @return iterable<string, array{non-empty-string, mixed, class-string<Throwable>, non-empty-string}>
     */
    public static function provideInvalidStoredValues(): iterable
    {
        yield 'invalid content' => ['email', 'not-an-email', InvalidEmailAddressException::class, 'Unable to typecast the `account.email` field: '];
        yield 'wrong type' => ['port', 'eighty', InvalidPayloadException::class, 'Unable to typecast the `account.port` field: A ' . Port::class . ' payload must be an integer, string given.'];
        yield 'unknown enum case' => ['country', 'XX', InvalidPayloadException::class, 'Unable to typecast the `account.country` field: "XX" is not a case of ' . CountryCode::class . '.'];
        yield 'malformed JSON' => ['location', '{"lat":', JsonException::class, 'Unable to typecast the `account.location` field: Syntax error'];
        yield 'JSON of the wrong shape' => ['location', '"52.52,13.405"', InvalidPayloadException::class, 'Unable to typecast the `account.location` field: A ' . GeoCoordinates::class . ' payload must be a JSON object, string given.'];
        yield 'neither JSON text nor an array' => ['location', 52, InvalidPayloadException::class, 'Unable to typecast the `account.location` field: A ' . GeoCoordinates::class . ' payload must be a JSON object, int given.'];
    }

    /**
     * @param non-empty-string $field
     * @param class-string<Throwable> $cause
     * @param non-empty-string $message
     */
    #[DataProvider('provideInvalidStoredValues')]
    public function testAnInvalidStoredValueFailsLoudly(string $field, mixed $stored, string $cause, string $message): void
    {
        try {
            $this->typecast()->cast([$field => $stored]);
            self::fail('No exception thrown.');
        } catch (TypecastException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
            self::assertInstanceOf($cause, $e->getPrevious());
            self::assertSame(0, $e->getCode());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideRawBoundedStrings(): iterable
    {
        yield 'JSON text' => ['{"value":"Hi","minLength":0,"maxLength":null}'];
        yield 'decoded array' => [['value' => 'Hi', 'minLength' => 0, 'maxLength' => null]];
        yield 'bare string' => ['Hi'];
    }

    /**
     * The payload carries its own bounds, so a raw value would let whoever
     * produced it choose them.
     */
    #[DataProvider('provideRawBoundedStrings')]
    public function testARawBoundedStringIsNeverWritten(mixed $raw): void
    {
        try {
            $this->typecast()->uncast(['title' => $raw]);
            self::fail('No exception thrown.');
        } catch (TypecastException $e) {
            self::assertSame('Unable to uncast the `account.title` field: A ' . BoundedString::class . ' cannot be read from untrusted input: its payload carries its own constraints.', $e->getMessage());
            self::assertInstanceOf(InvalidPayloadException::class, $e->getPrevious());
        }
    }

    /**
     * cast() cannot tell a database row from request data: by default it trusts
     * its input, so a BoundedString keeps whatever bounds the payload names.
     */
    public function testCastTrustsItsInputByDefault(): void
    {
        $title = $this->typecast()->cast(['title' => '{"value":"Hi","minLength":0,"maxLength":null}'])['title'];

        self::assertEquals(new BoundedString('Hi', 0), $title);
    }

    public function testUntrustedCastInputRefusesATrustedSourceOnlyRule(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The typecast rule of `account.title` is ' . BoundedString::class . ', which cannot be read from untrusted input; this handler is configured for untrusted cast input.');

        new ValueObjectTypecast('account', untrustedCastInput: true)->setRules(['email' => EmailAddress::class, 'title' => BoundedString::class]);
    }

    public function testUntrustedCastInputStillCastsEveryOtherKind(): void
    {
        $typecast = new ValueObjectTypecast('account', untrustedCastInput: true);
        $typecast->setRules(self::RULES_WITHOUT_TITLE);

        $cast = $typecast->cast(['email' => 'user@example.com', 'priority' => '9', 'location' => '{"lat":1,"lng":2}']);

        self::assertEquals(['email' => EmailAddress::from('user@example.com'), 'priority' => Priority::High, 'location' => GeoCoordinates::fromFloats(1.0, 2.0)], $cast);
    }

    public function testALeadingBackslashInARuleIsIgnored(): void
    {
        $typecast = new ValueObjectTypecast('account', new ValueObjectMapper([new EmailAddressObjectCodec()]));
        self::assertSame([], $typecast->setRules(['location' => '\\' . GeoCoordinates::class, 'email' => '\\' . EmailAddress::class]));

        self::assertEquals(
            ['location' => GeoCoordinates::fromFloats(1.0, 2.0), 'email' => EmailAddress::from('user@example.com')],
            $typecast->cast(['location' => '{"lat":1,"lng":2}', 'email' => '{"address":"user@example.com"}']),
        );
        self::assertSame(
            ['location' => '{"lat":1,"lng":2}', 'email' => '{"address":"user@example.com"}'],
            $typecast->uncast(['location' => GeoCoordinates::fromFloats(1.0, 2.0), 'email' => EmailAddress::from('user@example.com')]),
        );
    }

    public function testAJsonUnencodableCompositeFailsLoudly(): void
    {
        $typecast = $this->typecast();

        try {
            $typecast->uncast(['title' => new BoundedString("\xB1", 0, 10)]);
            self::fail('No exception thrown.');
        } catch (TypecastException $e) {
            self::assertSame('Unable to uncast the `account.title` field: Malformed UTF-8 characters, possibly incorrectly encoded', $e->getMessage());
            self::assertInstanceOf(JsonException::class, $e->getPrevious());
            self::assertSame(0, $e->getCode());
        }
    }

    public function testACustomCodecTurnsAClassIntoAJsonComposite(): void
    {
        $typecast = new ValueObjectTypecast('account', new ValueObjectMapper([new EmailAddressObjectCodec()]));
        $typecast->setRules(['email' => EmailAddress::class, 'location' => GeoCoordinates::class]);

        self::assertSame(['email' => '{"address":"user@example.com"}'], $typecast->uncast(['email' => EmailAddress::from('user@example.com')]));
        self::assertEquals(['email' => EmailAddress::from('user@example.com')], $typecast->cast(['email' => '{"address":"user@example.com"}']));
        self::assertEquals(['location' => GeoCoordinates::fromFloats(1.0, 2.0)], $typecast->cast(['location' => '{"lat":1,"lng":2}']));
    }

    private function typecast(): ValueObjectTypecast
    {
        $typecast = new ValueObjectTypecast('account');
        self::assertSame(['visits' => 'int'], $typecast->setRules([...self::RULES, 'visits' => 'int']));

        return $typecast;
    }
}
