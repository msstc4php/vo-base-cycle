# Changelog

## [1.0.0] - unreleased

First stable release: from here on a breaking change needs 2.0. What 1.x covers is listed in the README,
"Backward compatibility": the class and its constructor with its parameter names, the rule syntax and handler order,
the stored formats, and which exception each path throws. Not covered: message wording and anything `@internal`.

### Changed

- The handler's `TypecastException` message names the field only: ``Unable to typecast|uncast the `role.field`
  field.``. 0.1 appended the cause's message, which may quote part of the stored value. The cause is still the
  previous exception, unchanged.
- Constructor: `?ValueObjectMapper $mapper = null` instead of `ValueObjectMapper $mapper = new ValueObjectMapper()`;
  `null` still means the built-in codecs only. Passing a mapper, positionally or by name, and container injection of a
  bound mapper work as before.
- Requires `msstc4php/vo-base` ^1.4 (was ^1.1): an abstract class implementing `ResolvesConcreteClass` is a valid
  rule, and a mapper built from a `ProvidesCodecs` set (`new ValueObjectMapper(Codecs::all())`) is tested.

### Upgrading from 0.x

- Code or tests that matched the old message text (`... field: <cause>`) should read
  `$e->getPrevious()->getMessage()` or check the previous exception's class instead.
- Code that builds the handler (`new ValueObjectTypecast($role, $mapper, ...)`, container closures, schema
  instances) needs no change.
- Update `msstc4php/vo-base` to ^1.4 if it is pinned lower.

## 0.1.0 - 2026-11-11

First release. New integration, so no stability promise before 1.0.

- `Typecast\ValueObjectTypecast`: a Cycle ORM 2.x typecast handler (`CastableInterface` + `UncastableInterface`)
  for every value object and backed enum `ValueObjectMapper` supports; composites are stored as JSON text in the same
  format as the Doctrine types. Constructor `(role, ValueObjectMapper $mapper = new ValueObjectMapper(),
  bool $untrustedCastInput = false)`; a mapper bound in Cycle's container is injected.
- Requires `msstc4php/vo-base` ^1.1, `cycle/orm` ^2.10, `cycle/database` ^2.12, PHP >= 8.4.
