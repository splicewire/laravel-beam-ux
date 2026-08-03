<?php

namespace Splicewire\Beam\Ux\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Splicewire\Beam\Ux\Placement\FilePlacement;

/**
 * The **placement-keyed disk mirror** — the outbound projection that makes a beam-ux entry Publish land
 * a **git-trackable file** at its {@see FilePlacement} path
 * (`{namespace}/{type}/{slug}.{ext}`), rooted at the co-dev/dev disk the host configures under
 * `beam.ux.storage.disk`.
 *
 * Why this is distinct from the default `Stacked(Particle, Disk)` driver: that stack mirrors to disk
 * under the **particle id** key (a uuid), which is the source-of-record's address — NOT a path a human
 * or git wants. The paid `FilePlacement` policy derives the *human* path, but it needs the ENTRY (a
 * `StorageDriver::write` only gets a bare key). So the controller, which holds the entry, resolves the
 * placement path and hands it here — keeping the particle write source-of-record and this a pure,
 * additive projection (materialize-on-save, charter S2 / ADR-0165).
 *
 * The file it writes IS the body verbatim (pretty JSON) — for a Puck page that is the Puck `Data`
 * document itself, so the git diff reads as the composed page, not a wrapper envelope.
 *
 * **Degrade-not-fabricate:** when the storage disk is not configured (`beam.ux.storage.disk` null), this
 * is a **no-op** — a host that hasn't opted into a disk mirror is never forced to grow one.
 */
class PlacedDiskMirror
{
    public function __construct(private ?Filesystem $disk) {}

    /** Is a mirror disk configured? When false, {@see mirror()} is a no-op. */
    public function enabled(): bool
    {
        return $this->disk !== null;
    }

    /**
     * Project a body to the mirror disk at `$path` (the entry's placement path). No-op when no disk is
     * configured. Returns whether a file was written.
     *
     * @param  array<string, mixed>  $body
     */
    public function mirror(string $path, array $body, ?string $namespace = null): bool
    {
        if ($this->disk === null || $path === '') {
            return false;
        }

        $this->disk->put(
            $path,
            (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return true;
    }
}
