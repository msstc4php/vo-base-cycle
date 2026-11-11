# Known issues

## Cycle's default `Mapper` cannot hydrate `final` entities

`ProxyEntityFactory` generates a subclass: "The entity `...` class is final and can't be extended." Test entities are
non-final for that reason. Loaded objects are `<Class> Cycle ORM Proxy` instances carrying extra
`__cycle_orm_*` properties, so `assertEquals($entity, $loaded)` fails; compare declared properties.

## Unclaimed typecast rules vanish silently

`Factory::typecast()` discards whatever the last handler returns from `setRules()`. A value-object class nobody
claims is not an error in Cycle — it is just not typecast. Hence the `LogicException` for unsupported value objects.

## Typecast is built lazily

A misconfigured rule surfaces on the first hydrated row or the first persist of that role, not at ORM construction.
`findByPK()` on an empty table does not build it.

## Cycle's own enum rule is lenient

Cycle's `Typecast` casts enums with `tryFrom()` → unknown code becomes `null`. List `ValueObjectTypecast` first.

## SQLite type affinity in tests

An INTEGER column stores non-numeric text as TEXT (used by the wrong-type test); a TEXT column stores an integer
as text, so "wrong type" cannot be produced for string-backed columns on SQLite.

## Core strictness (vo-base 72d0fc5)

Int payloads must be canonical digit strings, float payloads plain decimals, DateRange ISO 8601 with offset.
PDO SQLite returns native ints/floats, so nothing in this package relied on the old leniency.

## Rector deprecation warning

`PostIncDecToPreIncDecRector` skip in the template rector.php is reported as deprecated/never registered. Template
noise; the file must stay byte-identical.

## `BoundedString` in an inherited role

A child role's `uncast()` turns a `BoundedString` into JSON text; if the parent role's handler also claims that
column it then sees a raw string and refuses it (`LogicException`). Untested — only matters if Cycle hands the same
column to both handlers in a JTI hierarchy; declare the rule on one role only.
