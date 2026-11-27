# msstc4php/vo-base-cycle

[Cycle ORM](https://cycle-orm.dev) 2.x typecast handler for [`msstc4php/vo-base`][core]: entity columns typed as
value objects or backed enums, read from and written to the database through the core's payload layer — the same
stored form the Doctrine types and the other bridges use.

## Install

Neither this package nor the core is on Packagist yet, so register both GitHub repositories:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/msstc4php/vo-base" },
        { "type": "vcs", "url": "https://github.com/msstc4php/vo-base-cycle" }
    ],
    "require": {
        "msstc4php/vo-base-cycle": "^1.0"
    }
}
```

Requires PHP >= 8.4, `msstc4php/vo-base` ^1.4, `cycle/orm` ^2.10 and `cycle/database` ^2.12. `cycle/database` is
required explicitly because older releases that `cycle/orm` ^2.10 would accept emit PHP 8.4 deprecations.

## Usage with a schema array

Put `ValueObjectTypecast` **before** Cycle's own `Typecast` and name the value-object class as the column's rule:

```php
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\SchemaInterface as S;
use MSSTC4PHP\ValueObject\Cycle\Typecast\ValueObjectTypecast;

'account' => [
    S::ENTITY => Account::class,
    S::TABLE => 'accounts',
    S::PRIMARY_KEY => 'id',
    S::COLUMNS => ['id', 'email', 'port', 'country', 'location'],
    S::TYPECAST => [
        'id' => 'int',                         // left to Cycle's Typecast
        'email' => EmailAddress::class,        // string-backed
        'port' => Port::class,                 // int-backed
        'country' => CountryCode::class,       // backed enum
        'location' => GeoCoordinates::class,   // composite, JSON text
    ],
    S::TYPECAST_HANDLER => [ValueObjectTypecast::class, Typecast::class],
],
```

Cycle instantiates the handler once per role and passes the role name; nothing else to configure. A rule may be
written with a leading backslash (`'\App\Money'`). An abstract class that picks its concrete subclass from the value
(vo-base's `ResolvesConcreteClass`, such as vo-documents' MRZ) is a valid rule too; the loaded value is an instance of
the subclass.

## Usage with annotated entities

With `cycle/annotated` and `cycle/schema-builder` the same goes into the attributes (not covered by this package's
tests):

```php
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\ORM\Parser\Typecast;

#[Entity(typecast: [ValueObjectTypecast::class, Typecast::class])]
class Account
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'string', nullable: true, typecast: EmailAddress::class)]
    public ?EmailAddress $email = null;

    #[Column(type: 'text', nullable: true, typecast: GeoCoordinates::class)]
    public ?GeoCoordinates $location = null;
}
```

## Custom codecs

A composite of your own needs a `PayloadCodec` (see the core). The handler takes a `ValueObjectMapper` (`null`, the
default, means the built-in codecs only); Cycle builds handlers through its Spiral container, which injects a mapper
bound there. This works with cached or compiled schemas, since the schema keeps only the class name:

```php
use Spiral\Core\Container;

$container = new Container();
$container->bindSingleton(ValueObjectMapper::class, new ValueObjectMapper([new MoneyCodec()]));
// a package's whole set through its ProvidesCodecs class, e.g. vo-documents' composite names:
// new ValueObjectMapper(\MSSTC4PHP\ValueObject\Documents\Codecs::all())
$orm = new ORM(new Factory($dbal, null, $container), $schema);
```

Without a container, put an instance in the schema, one per role (a handler keeps the rules of the role it was
built for):

```php
S::TYPECAST_HANDLER => [new ValueObjectTypecast('account', new ValueObjectMapper([new MoneyCodec()])), Typecast::class],
```

## Stored formats

| Kind | Example class | Column holds | Read back from |
|---|---|---|---|
| string-backed | `EmailAddress`, `Uuid`, `Url` | the string | a string |
| int-backed | `Port`, `PositiveInt` | the integer | an integer or a canonical digit string |
| float-backed | `Percentage`, `Latitude` | the number | a number or a plain decimal string |
| backed enum | `CountryCode`, `CurrencyCode`, any `BackedEnum` | the enum's value (code) | its value |
| composite | `GeoCoordinates`, `DateRange`, `IntRange`, `BoundedString` | JSON text, e.g. `{"lat":52.52,"lng":13.405}` | JSON text, or an already decoded array |

## Behaviour

- `null` stays `null` in both directions.
- An invalid stored value fails the load with Cycle's `TypecastException` (``Unable to typecast the `account.email`
  field.``); the value object's `InvalidValueObjectException` (or a `JsonException` for malformed JSON) is the
  previous exception. The message names the field only and never quotes the value, so stored data does not end up
  in logs; the previous exception's message is vo-base's own and may quote an excerpt. No silent `null` — unlike
  Cycle's own enum rule, which turns an unknown code into `null`.
- Writing an instance stores its payload. A raw value in a value-object column (an untyped property) is validated
  as untrusted input and stored in canonical form; an invalid one fails the write with `TypecastException`. A raw
  `BoundedString` always fails: its payload carries its own bounds.
- Rules that are not value-object or enum classes (`'int'`, `'datetime'`, callables, other classes) are left to the
  next handler. A value-object class without a payload form (e.g. `QueryParameter`) is a `LogicException` when
  Cycle first builds the role's typecast — otherwise Cycle would silently drop the rule.
- In `where()` clauses pass the stored form (`(string) $email`, `$country->value`); Cycle does not typecast query
  parameters.

## Trusted and untrusted input

`cast()` cannot tell a database row from other data, so it trusts its input. That matters for two paths that are
**trusted** although they look like input handling:

- `ORM::make($role, $data, typecast: true)`
- a mapper's `init()` / hydration with data you assembled yourself

Request data passed there can, for example, choose a `BoundedString`'s bounds. Validate the input first, or build
the value objects in code and pass instances. If a role's cast does receive request data, build its handler with
`untrustedCastInput: true`: every value is then read as untrusted input, and a rule for a class that must never come
from such input (`BoundedString`) is refused with a `LogicException` when Cycle builds the handler.

For one role, put an instance in that role's schema (keep your mapper if you have custom codecs):

```php
S::TYPECAST_HANDLER => [new ValueObjectTypecast('signup', $mapper, untrustedCastInput: true), Typecast::class],
```

A container binding switches **every** role at once — and any role with a `BoundedString` rule then fails when its
handler is built. Take the mapper as a parameter so a bound one (custom codecs) is still injected:

```php
$container->bind(
    ValueObjectTypecast::class,
    static fn (string $role, ValueObjectMapper $mapper) => new ValueObjectTypecast($role, $mapper, true),
);
```

## Inherited roles

With joined-table inheritance (`SchemaInterface::PARENT`) give each role its own `TYPECAST` and
`TYPECAST_HANDLER` for its own columns. Cycle chains the parent role's handler with the child's; a value already
converted by one is left alone by the other, so both tables round-trip (covered by a test).

## Backward compatibility

1.x follows semantic versioning; a breaking change needs 2.0. Covered by 1.x:

- **Code API:** the class `Typecast\ValueObjectTypecast` and its constructor
  `(string $role, ?ValueObjectMapper $mapper = null, bool $untrustedCastInput = false)` with these parameter names
  (named arguments); the `CastableInterface` / `UncastableInterface` methods it implements.
- **Names users write:** the rule syntax (a value-object or backed-enum FQCN, with or without a leading backslash) and
  the handler order (listed before Cycle's `Typecast`); container injection of a bound `ValueObjectMapper` and of the
  `role` argument.
- **Data:** the stored formats above. They are vo-base's payload layer, the same as the Doctrine types and the other
  bridges write, so a row written through one reads through another.
- **Exceptions:** `Cycle\ORM\Exception\TypecastException` (code 0, the cause as previous) for an invalid stored or
  raw value and for a composite that cannot be encoded as JSON; `LogicException`, unwrapped, when the handler is
  built for a value-object rule without a payload form, or for a trusted-source-only rule (`BoundedString`) under
  `untrustedCastInput`. The previous exception is vo-base's own exception, passed through unchanged.
- **Enums:** a backed-enum rule is claimed by this handler, and an unknown stored code fails instead of becoming
  `null`.
- **Versions:** a minor may add support for a new Cycle or PHP major; dropping a supported Cycle or PHP version needs
  a major, and so does raising the floor of any third-party dependency (Cycle, PHP). A minor may raise the floor of a
  `msstc4php/*` package within its major (those follow the same promise).

A minor never refuses a value an earlier 1.x accepted: the handler adds no validation of its own, the value objects
(vo-base and the domain packages) decide, and they follow the same rule.

Not covered: exception message wording (the handler's messages name a role, a field, a class or a debug type, never the value);
anything marked `@internal`.

## Development

```
COMPOSER=composer-local.json composer update --ignore-platform-req=php   # against ../vo-base
make check   # php -l, PHPStan level max, cs-fixer, composer validate/audit, Rector, deptrac
make test    # PHPUnit: unit suite plus the SQLite round trips
make infection
```

[core]: https://github.com/msstc4php/vo-base
