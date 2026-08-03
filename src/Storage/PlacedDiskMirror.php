<?php

namespace Splicewire\Beam\Ux\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Splicewire\Beam\Ux\Codegen\PuckPageCodegen;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\FilePlacement;
use Splicewire\Beam\Ux\Type\UxType;

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
 * **What it writes — dispatched on the entry (ADR-0164):** the file is the entry's body compiled back to
 * SOURCE, not the raw structured JSON (that json-verbatim mirror was the category error the "NEXT" slice
 * retired). A `page` whose body is a Puck `Data` document ({root, content, zones}) is CODEGEN'd through
 * {@see PuckPageCodegen} to a composed-JSX `.tsx` React component — generated OUTPUT, never read back
 * (the particle body's Puck Data stays the edit-truth the editor round-trips). Any OTHER body (an mdx
 * page, a hand-authored tsx `component`) rides its own {@see \Splicewire\Beam\Ux\Codec\BodyCodec}
 * `decode()` back to source text.
 *
 * **Write-safety (hard constraint):** a path's provenance is IMMUTABLE — a write may never flip a file
 * between generated and hand-authored. Codegen output opens with {@see PuckPageCodegen::MARKER}; the
 * mirror refuses to overwrite a marker-less (hand-authored) file with codegen output (the HARD
 * CONSTRAINT), AND symmetrically refuses to overwrite a marked (generated) file with authored/decoded
 * source. Regenerating a page or re-saving a component keeps provenance, so it is always allowed. Pages
 * (codegen) and components (authored) already live in distinct `{type}` subdirs, so this is
 * defense-in-depth against a path collision, never the primary gate.
 *
 * **Degrade-not-fabricate:** when the mirror disk is not configured (`beam.ux.storage.mirror_disk` null),
 * this is a **no-op** — a host that hasn't opted into a disk mirror is never forced to grow one.
 */
class PlacedDiskMirror
{
    public function __construct(private ?Filesystem $disk, private PuckPageCodegen $codegen) {}

    /** Is a mirror disk configured? When false, {@see mirror()} is a no-op. */
    public function enabled(): bool
    {
        return $this->disk !== null;
    }

    /**
     * Project an entry's body to the mirror disk at `$path` (the entry's placement path), compiled back
     * to source. No-op when no disk is configured. Returns whether a file was written.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws RuntimeException when a codegen write would clobber a hand-authored (unmarked) file
     */
    public function mirror(BeamUxEntry $entry, string $path, array $body): bool
    {
        if ($this->disk === null || $path === '') {
            return false;
        }

        if ($entry->type === UxType::Page && $this->isPuckData($body)) {
            // A Puck page: compile the structural Data to a composed-JSX .tsx (generated output).
            $text = $this->codegen->generate($body, (string) $entry->slug);
        } else {
            // An mdx page or a hand-authored component: decode the body back to its source text.
            $text = $entry->codec()->decode($body);
        }

        $this->guardProvenance($path, $text);

        $this->disk->put($path, $text);

        return true;
    }

    /** A Puck `Data` document is uniquely identified by `root` + a list `content` (an mdx page has neither). */
    private function isPuckData(array $body): bool
    {
        return array_key_exists('root', $body)
            && array_key_exists('content', $body)
            && is_array($body['content']);
    }

    /**
     * The write-safety invariant: a path's **provenance is immutable** — a write may never flip a file
     * between generated and hand-authored. So codegen output never clobbers a hand-authored file (the
     * HARD CONSTRAINT), AND — symmetrically — a decoded/authored write never clobbers generated output
     * (which would silently lose a Puck page if its body ever mis-routed to the codec branch). A write
     * that keeps the existing provenance (regenerate a generated page, re-save an authored component) is
     * always allowed.
     */
    private function guardProvenance(string $path, string $newText): void
    {
        if (! $this->disk->exists($path)) {
            return;
        }

        $existingGenerated = $this->codegen->isGenerated((string) $this->disk->get($path));
        $newGenerated = $this->codegen->isGenerated($newText);

        if ($existingGenerated !== $newGenerated) {
            $kind = $newGenerated ? 'codegen output' : 'hand-authored/decoded source';
            $held = $existingGenerated ? 'generated' : 'hand-authored';

            throw new RuntimeException(
                "Refusing to overwrite the {$held} file [{$path}] with {$kind}: a file's provenance is ".
                'immutable (generated ⇄ authored may not flip). Move or delete it first.'
            );
        }
    }
}
