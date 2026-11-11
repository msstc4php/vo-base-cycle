# Architecture

One area, one class: `src/Typecast/ValueObjectTypecast.php` (`deptrac.yaml` declares the single `Typecast` layer;
the core and Cycle are not layers, so they are free to use).

- Kind detection, payload shapes and validation live in the core's `ValueObjectMapper`. The handler adds only what is
  Cycle-specific: rule claiming, null handling, JSON text for composites, wrapping failures in `TypecastException`.
- Composite = `!isScalarBacked($class) && !BackedEnum` — both answers come from the mapper/PHP, not a local list, so
  a custom codec that turns a string-backed class into a composite is honoured.
- Read direction (`cast`) uses `fromPayload()` (trusted: the column was written by the application). Write direction
  for a raw non-instance value uses `fromUntrustedPayload()`; `BoundedString` (trusted-source-only codec) is refused
  before any decoding with a `LogicException`.
