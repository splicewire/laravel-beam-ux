<?php

namespace Splicewire\Beam\Ux\Provenance;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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
        return $this->reassertion($existing, $title, $source, $incomingOrigin, $body) === self::REASSERTED;
    }

    /**
     * {@see reassert()}, saying why a row was left: {@see REASSERTED}, {@see LEFT}, or the diagnostic of a stored body this
     * host cannot read (prefixed {@see UNREADABLE}). An unreadable row is kept, as an edited one is (ADR-0215 §2), but it
     * is not an edit, and a caller that reports must not count it as one (registry-docs ticket 16).
     */
    public function reassertion(BeamUxEntry $existing, ?string $title, string $source, string $incomingOrigin, ?array $body = null): string
    {
        // `$body`: the source already lowered to blocks by a caller that lowers as it registers (an MDX file on a host that
        // holds MDX as blocks); the codec's encode does not lower.
        return $this->reassertBody($existing, $title, $body ?? $existing->codec()->encode($source), $incomingOrigin);
    }

    public const REASSERTED = 'reasserted';

    /** Left as it is: edited, unchanged, out-ranked, cms or unknown origin, or the schema predates DOCS-06. */
    public const LEFT = 'left';

    /** The prefix of {@see reassertion()}'s answer for a row whose stored body this host cannot read. */
    public const UNREADABLE = 'stored body unreadable on this host';

    /** Seed shipped structured content without passing it through the author profile guard. */
    public function seedBody(array $coordinate, array $attributes, array $body, string $origin, ?\Splicewire\Beam\Ux\Data\EntryPageSettingsData $pageSettings = null): BeamUxEntry
    {
        if (! $this->asserts($origin)) {
            throw new \InvalidArgumentException('A system seed requires package:, disk: or pack: provenance.');
        }
        $publication = app(EntryPublication::class);
        $publication->requireVersionStore();
        $model = new BeamUxEntry;

        return $model->getConnection()->transaction(function () use ($model, $coordinate, $attributes, $body, $origin, $publication, $pageSettings) {
            $existing = $model->newQuery()->where($coordinate)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->reassertBody($existing, $attributes['title'] ?? null, $body, $origin);
                // Same repair as SeedsEntries: baseline only pins a body with no history,
                // never an unpublished draft which already has history.
                $publication->baseline($existing->refresh(), mirror: false);

                return $existing->refresh();
            }
            $entry = $model->newInstance(array_merge(BeamUxEntry::publishedMarkingAttributes(), $attributes, $coordinate));
            if ($pageSettings !== null) {
                $entry->forceFill(['child_fields' => $pageSettings->childFields, 'record_page' => $pageSettings->recordPage?->toArray()]);
            }
            if ($entry->getConnection()->getSchemaBuilder()->hasColumn($entry->getTable(), 'origin')) {
                $entry->origin = $origin;
                $entry->asserted_hash = Provenance::hash($entry->title, $entry->codec()->decode($body));
            }
            $entry->save();
            $this->writeAndPublish($entry, $body, $publication, 'seeded', $pageSettings);

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

    private function reassertBody(BeamUxEntry $existing, ?string $title, array $body, string $incomingOrigin): string
    {
        if (! Schema::hasColumn($existing->getTable(), 'asserted_hash')) {
            return self::LEFT; // Pre-migration: create-once stands; a row without provenance is never re-asserted.
        }

        $origin = (string) ($existing->origin ?? '');
        if (! $this->asserts($origin)) {
            return self::LEFT; // cms or unknown origin: never re-asserted by a seed or import.
        }

        if (Provenance::rank($incomingOrigin) < Provenance::rank($origin)) {
            return self::LEFT; // DOC-5: a weaker origin yields to the stronger incumbent.
        }

        // Unreadable is not edited (registry-docs ticket 16): both are kept, but this one is said, because nothing on this
        // host can tell whether the row is pristine, so no seed will re-assert it until the host can print its body.
        [$current, $why] = $this->stored($existing);
        if ($why !== null) {
            $row = ($existing->namespace ? "{$existing->namespace}." : '').$existing->slug;
            Log::warning("beam-ux: not re-asserted {$row} ({$origin}): ".self::UNREADABLE." ({$why})");

            return self::UNREADABLE." ({$why})";
        }

        if (! $this->pristine($existing, $current)) {
            return self::LEFT; // Edited: kept and reported by docs.diverged, never overwritten.
        }

        $newTitle = $title ?? $existing->title;
        // Like with like: the new source as it WOULD be stored, the form the asserted hash was taken over.
        $newHash = Provenance::hash($newTitle, $existing->codec()->decode($body));
        if ($newHash === (string) $existing->asserted_hash && ! $this->changesKind($existing, $body)) {
            return self::LEFT; // Pristine and the origin is unchanged: nothing to re-assert.
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
            return self::REASSERTED;
        }

        try {
            $this->compile->forEntry($existing, $existing->codec()->decode($body), force: true);
        } catch (CompilationFailed) {
            // Non-fatal, as in the seed path: reported by BeamUxArtifactAudit.
        }

        return self::REASSERTED;
    }

    /** Shared connection-bound system write; caller owns the transaction and provenance check. */
    private function writeAndPublish(BeamUxEntry $entry, array $body, EntryPublication $publication, string $label, ?\Splicewire\Beam\Ux\Data\EntryPageSettingsData $pageSettings = null): void
    {
        $written = $this->drivers->resolveOnEntryConnection($entry)->write((string) ($entry->particle_id ?? ''), $body, $entry->namespace);
        if ($written->key !== '') {
            $entry->particle_id = $written->key;
            $entry->save();
        }
        if ($pageSettings !== null) {
            $particle = $entry->fresh()->versionable();
            if (! $particle instanceof \Splicewire\Beam\Models\BeamParticle) {
                throw new \LogicException('Pack Page Fields require the entry\'s normal versioned particle.');
            }
            $meta = $particle->meta ?? [];
            $meta['pageSettings'] = $pageSettings->toArray();
            $particle->meta = $meta;
            $particle->save();
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

        return $this->pristine($entry, $this->currentBody($entry));
    }

    /** {@see isPristine()} over a body already read, so a caller that read it once does not print it twice. */
    private function pristine(BeamUxEntry $entry, ?string $current): bool
    {
        return Schema::hasColumn($entry->getTable(), 'asserted_hash') && (string) ($entry->asserted_hash ?? '') !== ''
            && $current !== null && Provenance::hash($entry->title, $current) === (string) $entry->asserted_hash;
    }

    private function asserts(string $origin): bool
    {
        return str_starts_with($origin, Provenance::DISK_PREFIX)
            || str_starts_with($origin, Provenance::PACKAGE_PREFIX)
            || str_starts_with($origin, Provenance::PACK_PREFIX);
    }

    /**
     * Stamp `asserted_hash` over the body the origin just WROTE, read back from the store: the one form isPristine(), the
     * re-assert and `docs.diverged` compare. A create site calls this after its body write, inside its transaction, so
     * a write that transforms the source (a blocks-kind lowering) cannot leave the row reading edited at birth
     * (registry-docs ticket 09/10). Writes only that column; a no-op pre-migration or when no body was written.
     */
    public function stampWritten(BeamUxEntry $entry): void
    {
        if (! Schema::hasColumn($entry->getTable(), 'asserted_hash') || ($written = $this->currentBody($entry)) === null) {
            return;
        }
        $entry->asserted_hash = Provenance::hash($entry->title, $written);
        if ($entry->isDirty('asserted_hash')) {
            $entry->save();
        }
    }

    /** The row's stored body as {@see isPristine()} reads it, so a re-stamp hashes exactly what pristine-ness compares. */
    public function currentBody(BeamUxEntry $entry): ?string
    {
        return $this->stored($entry)[0];
    }

    /**
     * Why this host cannot read a row's stored body (the lens's or the store's own diagnostic), or null when it can, or
     * when the row has no body to read at all (no particle). A row that is unreadable is neither pristine nor edited: it
     * is unjudged, and every reporter says so rather than calling it an edit (registry-docs ticket 16).
     */
    public function unreadable(BeamUxEntry $entry): ?string
    {
        return $this->stored($entry)[1];
    }

    /**
     * One read of the stored body, for a caller that needs both answers without printing it twice.
     *
     * @return array{0: ?string, 1: ?string} the stored body, or null and (when a body exists but can't be read) why
     */
    public function stored(BeamUxEntry $entry): array
    {
        if ($entry->particle_id === null) {
            return [null, null];
        }

        try {
            $item = $this->drivers->resolveOnEntryConnection($entry)->read((string) $entry->particle_id);
            if ($item === null) {
                return [null, "no stored item for particle {$entry->particle_id}"];
            }

            return [$entry->codec()->decode($item->body), null];
        } catch (Throwable $e) {
            return [null, self::diagnostic($e)];
        }
    }

    /** A lens refusal is a ValidationException whose message is generic; its first error is the lens's own words. */
    public static function diagnostic(Throwable $e): string
    {
        $first = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : null;

        return trim((string) ($first ?? $e->getMessage())) ?: $e::class;
    }
}
