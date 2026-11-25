# Architecture

One area, one class: `src/Typecast/ValueObjectTypecast.php` (`deptrac.yaml` declares the single `Typecast` layer;
the core and Cycle are not layers, so they are free to use).

- Kind detection, payload shapes and validation live in the core's `ValueObjectMapper`. The handler adds only what is
  Cycle-specific: rule claiming, null handling, JSON text for composites, wrapping failures in `TypecastException`.
- Constructor: `(non-empty-string $role, ?ValueObjectMapper $mapper = null, bool $untrustedCastInput = false)`;
  `null` → `new ValueObjectMapper()` in the body. The mapper is a constructor dependency so Cycle's Spiral container
  can inject a bound one (custom codecs with cached schemas). Not `= new ValueObjectMapper()` in the signature: Roave
  bc-check counts any change of a `new` initializer as a break (see known-issues). Frozen for 1.x (README "Backward compatibility").
- An abstract `ResolvesConcreteClass` rule (vo-base ^1.4) works without handler code: `is_subclass_of()` accepts the
  abstract class, the mapper's `fromPayload()` calls its `from()`, and `instanceof $column['class']` accepts the
  subclass instance. Pinned by the `AbstractCode`/`ShortCode`/`LongCode` fixtures.
- `setRules()` normalizes a rule with `ltrim($rule, '\\')`, stores `{class, composite}` per column; composite-ness
  comes from `ValueObjectMapper::isComposite()` once, never per row.
- Read direction (`cast`) uses `fromPayload()` (trusted) unless `untrustedCastInput`; then `fromUntrustedPayload()`
  and trusted-source-only rules (`BoundedString`) are refused in `setRules()`.
- Write direction for a raw non-instance value uses `fromUntrustedPayload()`; for a trusted-source-only class the
  trust refusal is checked before any JSON decoding, so the error is always the core's `InvalidPayloadException`.
