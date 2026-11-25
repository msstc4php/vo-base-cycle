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

## Roave bc-check and `new` in parameter initializers

Roave cannot compile `ValueObjectMapper $mapper = new ValueObjectMapper()` (same failure vo-base-laravel hit on
0.2.0). 1.0 uses `?ValueObjectMapper $mapper = null`. The CI job compares against the latest stable tag, so the
first run on the 1.0.0 commit still compares against v0.1.0 (with the old initializer) and may be red; it clears once
v1.0.0 is the base. Never reintroduce a `new` initializer in a public signature.

## Rector deprecation warning

`PostIncDecToPreIncDecRector` skip in the template rector.php is reported as deprecated/never registered. Template
noise; the file must stay byte-identical.

## `ORM::make(..., typecast: true)` is a trusted path

It runs the same `cast()` as hydration, so request data passed there is trusted: a `BoundedString` payload chooses
its own bounds (pinned by `testMakeWithTypecastTrustsItsInput`). Validate first, build instances in code, or use
`untrustedCastInput: true` for that role.

## Leading backslash in rules

A rule may be written `'\App\Foo'` (e.g. `'\' . Foo::class` or a copied FQCN). `class_exists()`/`is_subclass_of()` accept
it, but the mapper's codec map is keyed without it, so a composite would be missed. Rules are `ltrim`med.
