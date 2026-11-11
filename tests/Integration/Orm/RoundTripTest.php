<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Cycle\Tests\Integration\Orm;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Exception\TypecastException;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use DateTimeImmutable;
use LogicException;
use MSSTC4PHP\ValueObject\Cycle\Tests\Fixture\Account;
use MSSTC4PHP\ValueObject\Cycle\Tests\Fixture\Admin;
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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Spiral\Core\Container;

/**
 * A real Cycle ORM over SQLite in memory. Each load goes through a fresh ORM,
 * so the heap cannot hand back the persisted instance.
 */
#[CoversClass(ValueObjectTypecast::class)]
final class RoundTripTest extends TestCase
{
    private const array TYPECAST = [
        'id' => 'int',
        'email' => EmailAddress::class,
        'port' => Port::class,
        'discount' => Percentage::class,
        'country' => CountryCode::class,
        'priority' => Priority::class,
        'location' => GeoCoordinates::class,
        'period' => DateRange::class,
        'title' => BoundedString::class,
        'visits' => 'int',
    ];

    private DatabaseManager $dbal;

    #[Override]
    protected function setUp(): void
    {
        $this->dbal = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'sqlite']],
            'connections' => ['sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig())],
        ]));
        $this->database()->execute(
            'CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, port INTEGER, discount REAL,'
            . ' country TEXT, priority INTEGER, location TEXT, period TEXT, title TEXT, visits INTEGER)',
        );
    }

    public function testEveryKindSurvivesAWriteAndAFreshRead(): void
    {
        $account = new Account();
        $account->email = EmailAddress::from('user@example.com');
        $account->port = Port::from(8080);
        $account->discount = Percentage::from(12.5);
        $account->country = CountryCode::DE;
        $account->priority = Priority::High;
        $account->location = GeoCoordinates::fromFloats(52.52, 13.405);
        $account->period = new DateRange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), new DateTimeImmutable('2026-12-31T23:59:59.999999+00:00'));
        $account->visits = 3;

        new EntityManager($this->orm())->persist($account)->run();

        self::assertSame([[
            'id' => 1,
            'email' => 'user@example.com',
            'port' => 8080,
            'discount' => 12.5,
            'country' => 'DE',
            'priority' => 9,
            'location' => '{"lat":52.52,"lng":13.405}',
            'period' => '{"start":"2026-01-01T00:00:00.000000+00:00","end":"2026-12-31T23:59:59.999999+00:00"}',
            'title' => null,
            'visits' => 3,
        ]], $this->storedRows());

        $loaded = $this->load(1);
        self::assertNotSame($account, $loaded);
        $this->assertEqualFields($account, $loaded);
        self::assertSame(CountryCode::DE, $loaded->country);
        self::assertSame(Priority::High, $loaded->priority);
    }

    public function testNullColumnsStayNull(): void
    {
        new EntityManager($this->orm())->persist(new Account())->run();

        self::assertSame([[
            'id' => 1, 'email' => null, 'port' => null, 'discount' => null, 'country' => null, 'priority' => null,
            'location' => null, 'period' => null, 'title' => null, 'visits' => null,
        ]], $this->storedRows());
        $expected = new Account();
        $expected->id = 1;
        $this->assertEqualFields($expected, $this->load(1));
    }

    public function testAChangedValueObjectIsWrittenOnUpdate(): void
    {
        $orm = $this->orm();
        $account = new Account();
        $account->email = EmailAddress::from('old@example.com');
        $account->location = GeoCoordinates::fromFloats(1.0, 2.0);
        new EntityManager($orm)->persist($account)->run();

        $account->email = EmailAddress::from('new@example.com');
        $account->location = null;
        new EntityManager($orm)->persist($account)->run();

        $loaded = $this->load(1);
        self::assertEquals(EmailAddress::from('new@example.com'), $loaded->email);
        self::assertNull($loaded->location);
    }

    public function testAnInvalidStoredValueFailsTheLoad(): void
    {
        $this->database()->insert('accounts')->values(['email' => 'not-an-email'])->run();

        try {
            $this->load(1);
            self::fail('No exception thrown.');
        } catch (TypecastException $e) {
            self::assertStringStartsWith('Unable to typecast the `account.email` field: ', $e->getMessage());
            self::assertInstanceOf(InvalidEmailAddressException::class, $e->getPrevious());
        }
    }

    public function testAStoredValueOfTheWrongTypeFailsTheLoad(): void
    {
        // SQLite keeps text that does not look like a number in an INTEGER column.
        $this->database()->insert('accounts')->values(['port' => 'eighty'])->run();

        try {
            $this->load(1);
            self::fail('No exception thrown.');
        } catch (TypecastException $e) {
            self::assertStringStartsWith('Unable to typecast the `account.port` field: ', $e->getMessage());
            self::assertInstanceOf(InvalidPayloadException::class, $e->getPrevious());
        }
    }

    public function testABoundedStringKeepsItsBounds(): void
    {
        $orm = $this->orm();
        $account = new Account();
        $account->title = new BoundedString('Hi', 1, 5);
        new EntityManager($orm)->persist($account)->run();

        self::assertSame('{"value":"Hi","minLength":1,"maxLength":5}', $this->storedRows()[0]['title']);
        self::assertEquals(new BoundedString('Hi', 1, 5), $this->load(1)->title);
    }

    public function testAValueObjectWithoutPayloadFormFailsAtFirstUse(): void
    {
        $this->database()->insert('accounts')->values(['visits' => 1])->run();
        $orm = $this->orm(['filter' => QueryParameter::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('`account.filter`');

        $orm->getRepository('account')->findByPK(1);
    }

    public function testAHandlerInstanceCarriesCustomCodecs(): void
    {
        $handler = new ValueObjectTypecast('account', new ValueObjectMapper([new EmailAddressObjectCodec()]));
        $account = new Account();
        $account->email = EmailAddress::from('user@example.com');

        new EntityManager($this->orm(handler: $handler))->persist($account)->run();

        self::assertSame('{"address":"user@example.com"}', $this->storedRows()[0]['email']);
        $loaded = $this->orm(handler: new ValueObjectTypecast('account', new ValueObjectMapper([new EmailAddressObjectCodec()])))->getRepository('account')->findByPK(1);
        self::assertInstanceOf(Account::class, $loaded);
        self::assertEquals(EmailAddress::from('user@example.com'), $loaded->email);
    }

    /**
     * The form for a cached or compiled schema, which can only hold class names.
     */
    public function testAMapperBoundInTheContainerCarriesCustomCodecs(): void
    {
        $container = new Container();
        $container->bindSingleton(ValueObjectMapper::class, new ValueObjectMapper([new EmailAddressObjectCodec()]));

        $account = new Account();
        $account->email = EmailAddress::from('user@example.com');

        new EntityManager($this->orm(container: $container))->persist($account)->run();

        self::assertSame('{"address":"user@example.com"}', $this->storedRows()[0]['email']);
        $loaded = $this->orm(container: $container)->getRepository('account')->findByPK(1);
        self::assertInstanceOf(Account::class, $loaded);
        self::assertEquals(EmailAddress::from('user@example.com'), $loaded->email);
    }

    /**
     * ORM::make(..., typecast: true) runs cast(), which trusts its input: request
     * data given to it chooses a BoundedString's bounds. Pinned so a change shows.
     */
    public function testMakeWithTypecastTrustsItsInput(): void
    {
        $account = $this->orm()->make('account', ['title' => '{"value":"Hi","minLength":0,"maxLength":null}'], typecast: true);

        self::assertInstanceOf(Account::class, $account);
        self::assertEquals(new BoundedString('Hi', 0), $account->title);
    }

    public function testUntrustedCastInputRefusesBoundedStringBeforeMakeBuildsAnything(): void
    {
        $container = new Container();
        $container->bind(ValueObjectTypecast::class, $this->untrustedInputHandler(...));

        $orm = $this->orm(container: $container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('`account.title` is ' . BoundedString::class . ', which cannot be read from untrusted input');

        $orm->make('account', ['email' => 'user@example.com'], typecast: true);
    }

    public function testAContainerBoundUntrustedHandlerKeepsTheBoundMapper(): void
    {
        $container = new Container();
        $container->bindSingleton(ValueObjectMapper::class, new ValueObjectMapper([new EmailAddressObjectCodec()]));
        $container->bind(ValueObjectTypecast::class, $this->untrustedInputHandler(...));

        $orm = $this->orm(container: $container, withTitle: false);

        $account = $orm->make('account', ['email' => '{"address":"user@example.com"}'], typecast: true);
        self::assertInstanceOf(Account::class, $account);
        self::assertEquals(EmailAddress::from('user@example.com'), $account->email);

        $this->expectException(TypecastException::class);
        $orm->make('account', ['email' => '{"address":"not-an-email"}'], typecast: true);
    }

    /**
     * Joined-table inheritance: Cycle chains the parent role's handler before the
     * child's, so a value can pass both; each converts it once.
     */
    public function testAnInheritedRoleRoundTripsBothTables(): void
    {
        $this->database()->execute('CREATE TABLE admins (id INTEGER PRIMARY KEY, backup_email TEXT)');
        $admin = new Admin();
        $admin->email = EmailAddress::from('admin@example.com');
        $admin->location = GeoCoordinates::fromFloats(1.0, 2.0);
        $admin->title = new BoundedString('Boss', 1, 10);
        $admin->backupEmail = EmailAddress::from('backup@example.com');

        new EntityManager($this->orm(withAdmin: true))->persist($admin)->run();

        self::assertSame('admin@example.com', $this->storedRows()[0]['email']);
        self::assertSame('{"value":"Boss","minLength":1,"maxLength":10}', $this->storedRows()[0]['title']);
        self::assertSame([['id' => 1, 'backup_email' => 'backup@example.com']], $this->database()->select()->from('admins')->fetchAll());

        $loaded = $this->orm(withAdmin: true)->getRepository('admin')->findByPK(1);
        self::assertInstanceOf(Admin::class, $loaded);
        self::assertEquals(EmailAddress::from('admin@example.com'), $loaded->email);
        self::assertEquals(GeoCoordinates::fromFloats(1.0, 2.0), $loaded->location);
        self::assertEquals(new BoundedString('Boss', 1, 10), $loaded->title);
        self::assertEquals(EmailAddress::from('backup@example.com'), $loaded->backupEmail);
    }

    private function untrustedInputHandler(string $role, ValueObjectMapper $mapper): ValueObjectTypecast
    {
        self::assertNotSame('', $role);

        return new ValueObjectTypecast($role, $mapper, true);
    }

    /**
     * Cycle hands back a generated subclass with its own bookkeeping
     * properties, so only the declared ones are compared.
     */
    private function assertEqualFields(Account $expected, Account $actual): void
    {
        self::assertEquals(get_object_vars($expected), array_intersect_key(get_object_vars($actual), get_object_vars($expected)));
    }

    private function load(int $id): Account
    {
        $loaded = $this->orm()->getRepository('account')->findByPK($id);
        self::assertInstanceOf(Account::class, $loaded);

        return $loaded;
    }

    /**
     * @param array<non-empty-string, string> $extraRules
     */
    private function orm(
        array $extraRules = [],
        ValueObjectTypecast|string $handler = ValueObjectTypecast::class,
        ?Container $container = null,
        bool $withAdmin = false,
        bool $withTitle = true,
    ): ORM {
        $typecast = [...self::TYPECAST, ...$extraRules];
        if (!$withTitle) {
            unset($typecast['title']);
        }

        $admin = [
            SchemaInterface::ENTITY => Admin::class,
            SchemaInterface::DATABASE => 'default',
            SchemaInterface::TABLE => 'admins',
            SchemaInterface::PRIMARY_KEY => 'id',
            SchemaInterface::PARENT => 'account',
            SchemaInterface::COLUMNS => ['id' => 'id', 'backupEmail' => 'backup_email'],
            SchemaInterface::TYPECAST => ['id' => 'int', 'backupEmail' => EmailAddress::class],
            SchemaInterface::TYPECAST_HANDLER => [ValueObjectTypecast::class, Typecast::class],
            SchemaInterface::RELATIONS => [],
        ];

        return new ORM(new Factory($this->dbal, null, $container), new Schema([
            'account' => [
                SchemaInterface::ENTITY => Account::class,
                SchemaInterface::DATABASE => 'default',
                SchemaInterface::TABLE => 'accounts',
                SchemaInterface::PRIMARY_KEY => 'id',
                SchemaInterface::COLUMNS => array_combine(array_keys(self::TYPECAST), array_keys(self::TYPECAST)),
                SchemaInterface::TYPECAST => $typecast,
                SchemaInterface::TYPECAST_HANDLER => [$handler, Typecast::class],
                SchemaInterface::RELATIONS => [],
            ],
        ] + ($withAdmin ? ['admin' => $admin] : [])));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function storedRows(): array
    {
        $rows = [];
        foreach ($this->database()->select()->from('accounts')->fetchAll() as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    private function database(): DatabaseInterface
    {
        return $this->dbal->database('default');
    }
}
