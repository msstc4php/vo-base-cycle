# Conventions

## Which rules the handler claims

A string rule that is a `ValueObject` or `BackedEnum` subclass. Supported by the mapper → claimed. A `ValueObject`
the mapper cannot store → `LogicException` naming `role.field` (Cycle would otherwise drop it silently and hydrate
the raw string into a typed property, failing far away). Everything else falls through to the next handler.

## Trust

`cast()` trusts by default (database rows). `untrustedCastInput: true` is the opt-in for roles whose cast also sees
request data; it costs `BoundedString` on that role. `uncast()` always treats non-instances as untrusted.

## Errors

- Invalid stored/raw value → `Cycle\ORM\Exception\TypecastException`, code 0, message
  ``Unable to typecast|uncast the `role.field` field.`` — **no cause text** (1.0, owner rule O3: the bridge's own
  messages carry no part of the input; vo-base's messages quote excerpts). Previous = the core's
  `InvalidValueObjectException` (or `JsonException` for malformed JSON), unchanged.
  `testTheMessageCarriesNoPartOfTheValue` pins it for short, long and 100 KB inputs.
- Programmer errors (`LogicException`) are not wrapped.
- A composite raw value that is neither JSON text nor an array, or JSON that is not an object/array, is an
  `InvalidPayloadException` raised by the handler ("must be a JSON object").

## JSON

`json_encode($payload, JSON_THROW_ON_ERROR)` — same flags as the Doctrine `AbstractJsonVoType`, so the bytes match
across bridges. Floats with a zero fraction encode as integers (`1.0` → `1`); the GeoCoordinates codec accepts both.

## Tests

- `tests/Unit` — the handler alone; this is what Infection runs (`--testsuite=unit` in the template).
- `tests/Integration/Orm` — real ORM over SQLite `:memory:`; every load goes through a fresh `ORM` so the heap
  cannot return the persisted instance. Rows are also asserted raw via the query builder.
- `tests/Fixture/Account` is **not final** (see known-issues); compare loaded entities by declared properties only.
- vo-base's own test fixtures are autoload-dev there, not reachable here: local copies `AbstractCode`/`ShortCode`/
  `LongCode` (ResolvesConcreteClass, by length) and `ObjectCodecs` (a `ProvidesCodecs` set: `EmailAddressObjectCodec`,
  `PortObjectCodec`). No domain package in dev.
