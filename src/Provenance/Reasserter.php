<?php

namespace Splicewire\Beam\Ux\Provenance;

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
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
    public function reassert(BeamUxEntry $existing, ?string $title, string $source, string $incomingOrigin, ?array $body = null): bool
    {
        // `$body`: the source already lowered to blocks by a caller that lowers as it registers (an MDX file on a host that
        // holds MDX as blocks); the codec's encode does not lower.
        return $this->reassertBody($existing, $title, $body ?? $existing->codec()->encode($source), $incomingOrigin);
    }

    /** Seed shipped structured content without passing it through the author profile guard. */
    public function seedBody(array $coordinate, array $attributes, array $body, string $origin): BeamUxEntry
    {
        if (! $this->asserts($origin)) {
            throw new \InvalidArgumentException('A system seed requires package: or disk: provenance.');
        }
        $publication = app(EntryPublication::class);
        $publication->requireVersionStore();
        $model = new BeamUxEntry;

        return $model->getConnection()->transaction(function () use ($model, $coordinate, $attributes, $body, $origin, $publication) {
            $existing = $model->newQuery()->where($coordinate)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->reassertBody($existing, $attributes['title'] ?? null, $body, $origin);
                // Same repair as SeedsEntries: baseline only pins a body with no history,
                // never an unpublished draft which already has history.
                $publication->baseline($existing->refresh(), mirror: false);

                return $existing->refresh();
            }
            $entry = $model->newInstance(array_merge(BeamUxEntry::publishedMarkingAttributes(), $attributes, $coordinate));
            if ($entry->getConnection()->getSchemaBuilder()->hasColumn($entry->getTable(), 'origin')) {
                $entry->origin = $origin;
                $entry->asserted_hash = Provenance::hash($entry->title, $entry->codec()->decode($body));
            }
            $entry->save();
            $this->writeAndPublish($entry, $body, $publication, 'seeded');

            return $entry->refresh();
        });
    }

    /**
     * A pristine row holding source that now arrives as blocks: a change even where the printed text is the same. Once a
     * host holds MDX as blocks (ADR-0233 d9), a shipped file that is already canonical prints back to itself, so the hash
     * alone left it holding source on every later seed (EP-42 H2 receipt, 2026-10-26: tower's 8 docs pages).
     */
    private function changesKind(BeamUxEntry $existing, array $body): bool
    {
        return $body !== [] && array_is_list($body) && ! array_is_list((array) ($existing->particle?->payload ?? []));
    }

    private function reassertBody(BeamUxEntry $existing, ?string $title, array $body, string $incomingOrigin): bool
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

        if (! $this->isPristine($existing)) {
            return false; // Edited (or unreadable): kept and reported by docs.diverged, never overwritten.
        }

        $newTitle = $title ?? $existing->title;
        // Like with like: the new source as it WOULD be stored, the form the asserted hash was taken over.
        $newHash = Provenance::hash($newTitle, $existing->codec()->decode($body));
        if ($newHash === (string) $existing->asserted_hash && ! $this->changesKind($existing, $body)) {
            return false; // Pristine and the origin is unchanged: nothing to re-assert.
        }

        // Born published (ADR-0225 decision 2; review-r1, 2026-10-16): a re-asserted body is a new published version, so
        // the publication capability is checked BEFORE anything is written, and the write, the provenance stamp and the
        // new pin commit together. Writing then compiling under the old pin served bytes no version recorded.
        $publication = app(EntryPublication::class);
        $publication->requireVersionStore();

        $existing->getConnection()->transaction(function () use ($existing, $body, $newTitle, $newHash, $incomingOrigin, $origin, $publication): void {
            // Into the entry's OWN particle, so the re-asserted body is the next version of one history (a new particle per
            // re-assert restarted the history and orphaned the published versions).
            $existing->title = $newTitle;
            $existing->asserted_hash = $newHash;
            // A stronger incoming origin takes the coordinate (a host file supersedes a package stub).
            if (Provenance::rank($incomingOrigin) > Provenance::rank($origin)) {
                $existing->origin = $incomingOrigin;
            }
            $existing->save();
            $this->writeAndPublish($existing, $body, $publication, 'reasserted');
        });

        // Only an entry with no particle (nothing to version) still compiles separately.
        if ($existing->refresh()->published_version !== null) {
            return true;
        }

        try {
            $this->compile->forEntry($existing, $existing->codec()->decode($body), force: true);
        } catch (CompilationFailed) {
            // Non-fatal, as in the seed path: reported by BeamUxArtifactAudit.
        }

        return true;
    }

    /** Shared connection-bound system write; caller owns the transaction and provenance check. */
    private function writeAndPublish(BeamUxEntry $entry, array $body, EntryPublication $publication, string $label): void
    {
        $written = $this->drivers->resolveOnEntryConnection($entry)->write((string) ($entry->particle_id ?? ''), $body, $entry->namespace);
        if ($written->key !== '') {
            $entry->particle_id = $written->key;
            $entry->save();
        }
        // Every caller is a seed or a re-import (seeded / reasserted), never an author save: no mirror write.
        $publication->publishWritten($entry->refresh(), $label, mirror: false);
    }

    /**
     * Whether a row still holds exactly what its origin last asserted: its title and stored body hash to `asserted_hash`.
     * A row without provenance, an edited one, or one whose body can't be read is not.
     */
    public function isPristine(BeamUxEntry $entry): bool
    {
        if (! Schema::hasColumn($entry->getTable(), 'asserted_hash') || (string) ($entry->asserted_hash ?? '') === '') {
            return false;
        }
        $current = $this->currentBody($entry);

        return $current !== null && Provenance::hash($entry->title, $current) === (string) $entry->asserted_hash;
    }

    private function asserts(string $origin): bool
    {
        return str_starts_with($origin, Provenance::DISK_PREFIX)
            || str_starts_with($origin, Provenance::PACKAGE_PREFIX);
    }

    /** The row's stored body as {@see isPristine()} reads it, so a re-stamp hashes exactly what pristine-ness compares. */
    public function currentBody(BeamUxEntry $entry): ?string
    {
        if ($entry->particle_id === null) {
            return null;
        }

        try {
            $item = $this->drivers->resolveOnEntryConnection($entry)->read((string) $entry->particle_id);

            return $item === null ? null : $entry->codec()->decode($item->body);
        } catch (Throwable) {
            return null;
        }
    }
}
