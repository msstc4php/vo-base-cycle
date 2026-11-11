# Changelog

## 0.1.0 - 2026-11-11

First release. New integration, so no stability promise before 1.0.

- `Typecast\ValueObjectTypecast`: a Cycle ORM 2.x typecast handler (`CastableInterface` + `UncastableInterface`)
  for every value object and backed enum `ValueObjectMapper` supports; composites are stored as JSON text in the same
  format as the Doctrine types.
- Requires `msstc4php/vo-base` ^1.1, `cycle/orm` ^2.10, `cycle/database` ^2.12, PHP >= 8.4.
