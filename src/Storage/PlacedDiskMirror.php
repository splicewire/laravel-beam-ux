<?php

namespace Splicewire\Beam\Ux\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Splicewire\Beam\Ux\Files\MdxPageFile;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
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
 * or git wants. The `FilePlacement` policy derives the *human* path, but it needs the ENTRY (a
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
        // ADR-0233 d6: a Hosted site's page has no file, so a tenant-resident entry is never mirrored.
        if ($this->disk === null || $path === '' || ! app(EntryResidency::class)->isCentralResident($entry)) {
            return false;
        }

        $this->disk->put($path, $this->bytes($entry, $body));

        return true;
    }

    /**
     * The bytes the mirror writes for an entry's body: its codec's source, and for an MDX page that holds blocks its
     * page settings as the frontmatter header first (ADR-0233 d3), since such a page keeps them out of its body. The
     * Release recording and the Files action read the same bytes, so a commit and a comparison match the file.
     *
     * @param  array<string, mixed>  $body
     */
    public function bytes(BeamUxEntry $entry, array $body): string
    {
        $source = $entry->codec()->decode($body);

        return MdxPageFile::applies($entry) && array_is_list($body)
            ? MdxPageFile::print(MdxPageFile::settingsOf($entry), $source) : $source;
    }

    /**
     * Remove the file a publish moved an entry away from (ADR-0233 d5: its File Language changed), only while it still
     * holds the bytes that were published there. A file someone edited since is left in place, never destroyed. Returns
     * whether it was removed.
     */
    public function removeIfUnchanged(string $path, string $bytes): bool
    {
        if ($this->disk === null || $path === '' || ! $this->disk->exists($path) || $this->disk->get($path) !== $bytes) {
            return false;
        }

        return $this->remove($path);
    }

    /**
     * Delete the entry's mirror file at `$path` — the projection of a CLEARED entry, which has no body
     * at all. Distinct from {@see mirror()} with an empty body on purpose: an author may save an empty
     * document, and that writes an empty file they chose to have; only
     * {@see \Splicewire\Beam\Ux\Publish\EntryPublication::clear()} removes the file. No-op when no disk is
     * configured or nothing is there. Returns whether a file was removed.
     */
    public function remove(string $path): bool
    {
        if ($this->disk === null || $path === '' || ! $this->disk->exists($path)) {
            return false;
        }

        if (! $this->disk->delete($path)) {
            return false;
        }

        // Prune the placement directories the removal emptied (`{namespace}/{type}/`), so a cleared page
        // leaves the git-tracked mirror root as it was before the page was first published — no empty
        // `starter/page/` behind. Only EMPTY directories go; anything holding another file stays.
        for ($dir = dirname($path); $dir !== '.' && $dir !== '' && $dir !== '/'; $dir = dirname($dir)) {
            if ($this->disk->files($dir) !== [] || $this->disk->directories($dir) !== []) {
                break;
            }

            $this->disk->deleteDirectory($dir);
        }

        return true;
    }
}
