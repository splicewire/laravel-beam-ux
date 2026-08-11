<?php

namespace Splicewire\Beam\Ux\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\FilePlacement;

/**
 * The **placement-keyed disk mirror** — the outbound projection that makes a beam-ux entry Publish land
 * a **git-trackable file** at its {@see FilePlacement} path
 * (`{namespace}/{type}/{slug}.{ext}`), rooted at the co-dev/dev disk the host configures under
 * `beam.ux.storage.mirror_disk`.
 *
 * Why this is distinct from the default `Stacked(Particle, Disk)` driver: that stack mirrors to disk
 * under the **particle id** key (a uuid), which is the source-of-record's address — NOT a path a human
 * or git wants. The paid `FilePlacement` policy derives the *human* path, but it needs the ENTRY (a
 * `StorageDriver::write` only gets a bare key). So the controller, which holds the entry, resolves the
 * placement path and hands it here — keeping the particle write source-of-record and this a pure,
 * additive projection (materialize-on-save, charter S2 / ADR-0165).
 *
 * **What it writes:** the file is the entry's body compiled back to SOURCE via its own
 * `Splicewire\Beam\Ux\Codec\BodyCodec` `decode()` — not the raw structured JSON (that
 * json-verbatim mirror was the category error the "NEXT" slice retired). The former Puck-page codegen
 * branch (compiling a structural Puck `Data` body to composed-JSX) is retired (ADR-0016 — body format
 * is `@splicewire/beam-ux/blockdoc`'s `JsonNode[]` tree, not Puck); every body now takes the same
 * codec-decode path, so the write-safety "provenance is immutable, generated ⇄ authored may not flip"
 * guard this class used to enforce no longer has a generated kind to guard against and is retired with
 * it.
 *
 * **Degrade-not-fabricate:** when the mirror disk is not configured (`beam.ux.storage.mirror_disk` null),
 * this is a **no-op** — a host that hasn't opted into a disk mirror is never forced to grow one.
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
     * Project an entry's body to the mirror disk at `$path` (the entry's placement path), compiled back
     * to source via the entry's codec. No-op when no disk is configured. Returns whether a file was
     * written.
     *
     * @param  array<string, mixed>  $body
     */
    public function mirror(BeamUxEntry $entry, string $path, array $body): bool
    {
        if ($this->disk === null || $path === '') {
            return false;
        }

        $this->disk->put($path, $entry->codec()->decode($body));

        return true;
    }
}
