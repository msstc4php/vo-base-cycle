# Integrations — Cycle ORM

## How Cycle builds the handler

- `Cycle\ORM\Factory::typecast()` reads `SchemaInterface::TYPECAST` (rules) and `TYPECAST_HANDLER` (class string,
  object, or a list of them). A list becomes the `@internal` `CompositeTypecast`.
- Class strings are instantiated through `Spiral\Core\Container::make($class, ['database' => ..., 'schema' => ...,
  'role' => ...])`; unused named arguments are ignored. A class-typed parameter is resolved from the container
  (bound instance, else autowired — so `ValueObjectMapper $mapper = new ValueObjectMapper()` gets a bound mapper),
  and `bind(ValueObjectTypecast::class, fn (string $role) => ...)` closures receive `role` by name. Verified on
  spiral/core 2.9.0 and 3.18.2. Objects in the schema are used as they are — hence "one instance per role".
- `setRules()` is called on each handler in list order; every handler returns the rules it did not claim. **The
  rest after the last handler is dropped without any error.**
- `cast()` runs handlers in list order; `uncast()` in reverse order (`array_unshift`).
- A role with `SchemaInterface::PARENT` gets `CompositeTypecast($parentHandler, $handler)`, so a value can pass
  through two handlers: `cast()` skips values that are already instances. `RoundTripTest::testAnInheritedRoleRoundTripsBothTables`
  (JTI, parent columns incl. GeoCoordinates and BoundedString, child column EmailAddress) passes: each table's
  insert is uncast by its own role, nothing is converted twice.
- `ORM::make($role, $data, typecast: true)` and hydration both run `cast()`; there is no way to tell the source.
- A container binding of `ValueObjectTypecast` applies to every role; take `ValueObjectMapper $mapper` as a closure
  parameter to keep a bound mapper (tested: `testAContainerBoundUntrustedHandlerKeepsTheBoundMapper`).
- The handler is built lazily per role (`TypecastProvider::getTypecast()`), on first hydration or persist — not when
  the ORM is constructed.
- Cycle calls `uncast()` only in write commands and embedded relations; query parameters (`where()`) are never
  typecast. `Insert` casts a generated primary key through `cast()`.

## Order relative to Cycle's `Typecast`

`ValueObjectTypecast` must come first: Cycle's `Typecast` claims every `BackedEnum` rule and casts with `tryFrom()`,
which turns an unknown stored code into `null`. Listed first, this handler claims enums too and fails loudly.

## Version floors (verified 2026-11-11 UTC with `--prefer-lowest`)

| Package | Floor | Why |
|---|---|---|
| `cycle/orm` | 2.10.0 | First release without PHP 8.4 compile-time deprecations in its own code (2.0.0–2.9 run the suite fine but emit ~30 implicit-nullable deprecations). The handler chain itself exists since 2.0.0. |
| `cycle/database` | 2.12.0 | 2.11 and older emit ~60 PHP 8.4 deprecations. Also used directly by the tests. |
| `spiral/core` | 2.9.0 (transitive) | Whatever cycle/orm 2.10 resolves; no issue seen. |

Highest verified: cycle/orm 2.18.1, cycle/database 2.23.3, spiral/core 3.18.2.

## cycle/annotated

v4.6.1: `#[Entity(typecast: ...)]` takes `non-empty-string|non-empty-string[]|null`, `#[Column(typecast: ...)]` takes
`mixed`. The README example uses both; not covered by tests (would need schema-builder in dev).
