<?php

namespace Splicewire\Beam\Ux\Compile;

use Illuminate\Contracts\Filesystem\Filesystem;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Where a compiled body lives, and what makes one **current** (ADR-0209 §7).
 *
 * The artifact is keyed by the entry's id and its **particle version**, which is the whole reason the
 * ADR chose that key: a version-keyed path means a stale artifact is not "an old file at the same
 * address" but a *different* address that simply is not there, so {@see has()} answers both "compiled?"
 * and "compiled from the CURRENT body?" with one existence check — and the same key is a free strong
 * ETag on the public route.
 *
 * `head_version` is the HEAD **pin**, and it is an opaque per-snapshot token — not a counter, and not
 * ordered. {@see \Rushing\Versioning\Store\EloquentVersionStore} writes the `Version` row's id, which
 * is a UUID (`HasUuids`); {@see \Rushing\Versioning\Git\GitVersionStore} writes the commit SHA. Neither
 * is comparable to another, so the invariant this key rests on is **changes-when-the-content-changes**,
 * never monotonicity: the value is hashed straight into the address below, and must never be ordered,
 * ranged, or `max()`d. (An earlier version of this paragraph called it "the particle's own snapshot
 * counter" and reasoned about a "monotonic" key. That was wrong under the Eloquent store it was written
 * against, before the git driver existed.)
 *
 * A particle that has never been versioned (or a host whose particle table predates the column) falls
 * back to the particle's `updated_at`, then to the entry's — coarser, but still moving on every write,
 * which is all this key requires. What must never happen is degrading to a CONSTANT, which would make
 * every artifact permanently "current" and silently serve stale code forever.
 */
class EntryArtifactStore
{
    public function __construct(
        private Filesystem $disk,
        private string $root = 'beam-ux/artifacts',
    ) {}

    /**
     * The **compiler generation** — bumped whenever `compile.mjs` changes the SHAPE of what it emits.
     *
     * ⚠️ **This paragraph used to say "the version below hashes the BODY". It does not, and never did.**
     * {@see version()} hashes `head_version ?? particle.updated_at ?? entry.updated_at` — a HEAD pin or a
     * TIMESTAMP, never the content. Measured 2026-08-31 at `~/Herd/splicewire-app`: 32 of 32 entry-linked
     * particles carry `head_version IS NULL` (39 of 39 particles in `public`, in fact), so on the
     * flagship today the artifact address is a hash of `updated_at` and nothing else.
     *
     * The consequence, which the false docblock hid: **ANY particle write stales the artifact**, including
     * a byte-identical no-op re-write. `disk-to-record` touching a row it did not change is enough to
     * orphan the compiled file and force a recompile. That is a live question, not a repair to make here
     * — see beam-docs-satellite ticket 61.
     *
     * The generation constant is still needed for the reason below, and the reason survives the
     * correction: an `updated_at` key is the right key for "has this row been written?" and the wrong one
     * for "was this produced by the current compiler?". Ticket 07 changed the artifact
     * from an ES module with a bare `react/jsx-runtime` import into a runtime-injected module
     * (ADR-0209 §7, amended) — the bodies were untouched, so every artifact kept its address, and a
     * browser holding the previous file under that address went on using it. The URL is treated as
     * effectively immutable precisely BECAUSE the version moves on every write; a compiler change writes
     * no row, so it moves nothing a write-keyed address can see.
     *
     * Bump this on any change to the emitted shape. It costs one recompile and nothing else.
     */
    private const GENERATION = '3';

    /**
     * The version key an artifact is stamped with. Short, opaque, and stable for an UNWRITTEN row
     * compiled by an unchanged compiler — callers treat it as a token, never parse it.
     *
     * ⚠️ It keys on the WRITE, not the content: the HEAD pin when the particle is versioned, otherwise
     * the particle's `updated_at`, otherwise the entry's. On the flagship the first is null for every
     * row, so a byte-identical re-write of a body still produces a NEW address and orphans the artifact
     * at the old one. See the correction on {@see GENERATION}.
     */
    public function version(BeamUxEntry $entry): string
    {
        try {
            $particle = $entry->particle_id !== null ? $entry->particle : null;
        } catch (\Throwable) {
            // beam-core's particle table is absent (a host that installed beam-ux alone, or a harness
            // with a hand-built schema). Degrade to the entry's own timestamp rather than fataling: the
            // key still moves on every write, it is just coarser. What must never happen is a CONSTANT,
            // which would make every artifact permanently "current" and silently serve stale code.
            $particle = null;
        }

        $source = $particle?->getAttribute('head_version')
            ?? $particle?->getAttribute('updated_at')
            ?? $entry->getAttribute('updated_at')
            ?? '0';

        if ($source instanceof \DateTimeInterface) {
            $source = $source->format('U.u');
        }

        return substr(hash('xxh128', self::GENERATION.':'.$source), 0, 16);
    }

    /** The disk path of an entry's artifact at a given version (its CURRENT version by default). */
    public function path(BeamUxEntry $entry, ?string $version = null): string
    {
        $version ??= $this->version($entry);

        return "{$this->root}/{$entry->getKey()}/{$version}.js";
    }

    /** Whether the entry has an artifact compiled from its current body. */
    public function has(BeamUxEntry $entry): bool
    {
        return $this->disk->exists($this->path($entry));
    }

    /** The current artifact's contents, or null when it has not been compiled (or has gone stale). */
    public function read(BeamUxEntry $entry): ?string
    {
        $path = $this->path($entry);

        return $this->disk->exists($path) ? (string) $this->disk->get($path) : null;
    }

    /**
     * Write the artifact for the entry's current version and drop every older one for that entry, so a
     * long-lived site does not accumulate one dead module per edit. Pruning is per-entry and version-
     * scoped: nothing else's artifacts are ever in the blast radius.
     */
    public function put(BeamUxEntry $entry, string $code): string
    {
        $path = $this->path($entry);
        $this->disk->put($path, $code);

        foreach ($this->disk->files("{$this->root}/{$entry->getKey()}") as $existing) {
            if ($existing !== $path) {
                $this->disk->delete($existing);
            }
        }

        return $path;
    }

    /** Drop every artifact for an entry (a deleted entry, or a forced recompile). */
    public function forget(BeamUxEntry $entry): void
    {
        $this->disk->deleteDirectory("{$this->root}/{$entry->getKey()}");
    }

    public function disk(): Filesystem
    {
        return $this->disk;
    }
}
