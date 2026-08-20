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
 * `head_version` is the particle's own snapshot counter. A particle that has never been versioned (or a
 * host whose particle table predates the column) falls back to the particle's `updated_at`, then to the
 * entry's — degrading to a coarser but still monotonic key rather than to a constant, which would make
 * every artifact permanently "current" and silently serve stale code forever.
 */
class EntryArtifactStore
{
    public function __construct(
        private Filesystem $disk,
        private string $root = 'beam-ux/artifacts',
    ) {}

    /**
     * The version key an artifact is stamped with. Short, opaque, and stable for an unchanged body —
     * callers treat it as a token, never parse it.
     */
    public function version(BeamUxEntry $entry): string
    {
        try {
            $particle = $entry->particle_id !== null ? $entry->particle : null;
        } catch (\Throwable) {
            // beam-core's particle table is absent (a host that installed beam-ux alone, or a harness
            // with a hand-built schema). Degrade to the entry's own timestamp rather than fataling: the
            // key stays monotonic, it is just coarser. What must never happen is degrading to a CONSTANT,
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

        return substr(hash('xxh128', (string) $source), 0, 16);
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
