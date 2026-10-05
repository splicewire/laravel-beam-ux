<?php

namespace Splicewire\Beam\Ux\Provenance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Throwable;

/**
 * Re-assert a PRISTINE package:/disk: row from its origin (DOCS-06, ADR-0215 §2).
 *
 * Create-once is right for a `cms` row a person authored, and wrong for a row whose source is a
 * package stub or a git file — those sources change and the row never heard about it. So, on a
 * re-seed or re-import, a row whose origin is `package:` or `disk:` and whose stored body still
 * equals what its origin last asserted (PRISTINE) is rewritten from the new source and its
 * `asserted_hash` moves with it. An EDITED row (stored body ≠ `asserted_hash`) is never overwritten —
 * `docs.diverged` reports it. A `cms` row is never touched. Placement (`segment`, `parent_id`,
 * `access`, `workflow_marking`) is site-owned and never re-asserted; only the body, title and hash
 * move. DOC-5 precedence: a weaker incoming origin never re-asserts over a stronger incumbent (a
 * package seed leaves a host disk row alone), and a stronger one takes the coordinate.
 */
final class Reasserter
{
    public function __construct(
        private StorageDriverResolver $drivers,
        private CompileEntryBody $compile,
    ) {}

    /**
     * Returns true when the row was rewritten, false when it was left as-is (edited, cms/unknown,
     * out-ranked, source unchanged, or the schema predates DOCS-06).
     */
    public function reassert(BeamUxEntry $existing, ?string $title, string $source, string $incomingOrigin): bool
    {
        if (! Schema::hasColumn($existing->getTable(), 'asserted_hash')) {
            return false; // Pre-migration: create-once stands; a row without provenance is never re-asserted.
        }

        $origin = (string) ($existing->origin ?? '');
        if (! $this->asserts($origin)) {
            return false; // cms or unknown origin: never re-asserted by a seed or import.
        }

        if (Provenance::rank($incomingOrigin) < Provenance::rank($origin)) {
            return false; // DOC-5: a weaker origin yields to the stronger incumbent.
        }

        $current = $this->currentBody($existing);
        if ($current === null) {
            return false;
        }

        if (Provenance::hash($existing->title, $current) !== (string) $existing->asserted_hash) {
            return false; // Edited: kept and reported by docs.diverged, never overwritten.
        }

        $newTitle = $title ?? $existing->title;
        $newHash = Provenance::hash($newTitle, $source);
        if ($newHash === (string) $existing->asserted_hash) {
            return false; // Pristine and the origin is unchanged: nothing to re-assert.
        }

        DB::transaction(function () use ($existing, $source, $newTitle, $newHash, $incomingOrigin, $origin): void {
            $written = $this->drivers->resolve($existing)->write('', $existing->codec()->encode($source), $existing->namespace);
            $existing->title = $newTitle;
            $existing->asserted_hash = $newHash;
            // A stronger incoming origin takes the coordinate (a host file supersedes a package stub).
            if (Provenance::rank($incomingOrigin) > Provenance::rank($origin)) {
                $existing->origin = $incomingOrigin;
            }
            if ($written->key !== '') {
                $existing->particle_id = $written->key;
            }
            $existing->save();
        });

        try {
            $this->compile->forEntry($existing->refresh(), $source, force: true);
        } catch (CompilationFailed) {
            // Non-fatal, as in the seed path: reported by BeamUxArtifactAudit.
        }

        return true;
    }

    private function asserts(string $origin): bool
    {
        return str_starts_with($origin, Provenance::DISK_PREFIX)
            || str_starts_with($origin, Provenance::PACKAGE_PREFIX);
    }

    private function currentBody(BeamUxEntry $entry): ?string
    {
        if ($entry->particle_id === null) {
            return null;
        }

        try {
            $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);

            return $item === null ? null : $entry->codec()->decode($item->body);
        } catch (Throwable) {
            return null;
        }
    }
}
