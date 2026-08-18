<?php

namespace Splicewire\Beam\Ux\Codec;

use BackedEnum;

/**
 * A zero-method marker a BACKED enum opts into (`enum Foo: string implements UxFormatCase`) — the
 * seam that actually makes {@see CodecRegistry} open, not just documented as open.
 *
 * Before this: {@see BodyCodec::format()} returned the CONCRETE {@see \Splicewire\Beam\Ux\Format\UxFormat}
 * class. That's a real lock-in a docblock claiming openness can't paper over — `UxFormat` growing a
 * new case is harmless (existing code checking known cases is unaffected), but REGISTERING a codec for
 * a format the class doesn't already name is impossible: you'd have to regenerate `UxFormat` with the
 * new case, THEN write a `BodyCodec` returning it, THEN register — a compile-time round trip through
 * one shared class, every time, for every host. `UxFormat` was never actually openable by a host, only
 * by whoever maintains the package.
 *
 * Typing `format(): UxFormatCase` instead removes that: ANY backed enum can `implements` this,
 * including one a host defines entirely on its own for a format `UxFormat` never heard of — no
 * regeneration, no touching the shared class at all. `UxFormat` demotes from "the type" to "a
 * convenience" — a generated snapshot of what's registered as of the last `codegen:generate`
 * equivalent, useful for autocomplete + `Rule::enum()`, but no longer the ceiling on what CAN be
 * registered.
 *
 * `extends BackedEnum` costs nothing to declare (every backed enum already satisfies `BackedEnum`
 * natively — `cases()`/`from()`/`tryFrom()` are synthesized by PHP itself) and buys real type safety:
 * a plain `class Foo implements UxFormatCase` (not an enum) still fails to satisfy `BackedEnum`'s
 * built-in contract, so this can't be accidentally implemented by something that isn't actually a
 * backed enum.
 */
interface UxFormatCase extends BackedEnum {}
