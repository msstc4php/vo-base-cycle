# Conventions

## Which rules the handler claims

A string rule that is a `ValueObject` or `BackedEnum` subclass. Supported by the mapper → claimed. A `ValueObject`
the mapper cannot store → `LogicException` naming `role.field` (Cycle would otherwise drop it silently and hydrate
the raw string into a typed property, failing far away). Everything else falls through to the next handler.

## Errors

- Invalid stored/raw value → `Cycle\ORM\Exception\TypecastException`, code 0, message in Cycle's own format
  ``Unable to typecast|uncast the `role.field` field: <cause>``, previous = the core's `InvalidValueObjectException`
  (or `JsonException` for malformed JSON).
- Programmer errors (`LogicException`) are not wrapped.

## JSON

`json_encode($payload, JSON_THROW_ON_ERROR)` — same flags as the Doctrine `AbstractJsonVoType`, so the bytes match
across bridges. Floats with a zero fraction encode as integers (`1.0` → `1`); the GeoCoordinates codec accepts both.

## Tests

- `tests/Unit` — the handler alone; this is what Infection runs (`--testsuite=unit` in the template).
- `tests/Integration/Orm` — real ORM over SQLite `:memory:`; every load goes through a fresh `ORM` so the heap
  cannot return the persisted instance. Rows are also asserted raw via the query builder.
- `tests/Fixture/Account` is **not final** (see known-issues); compare loaded entities by declared properties only.
