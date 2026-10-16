<?php

namespace Splicewire\Beam\Ux\Seed;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The shared "seed one page entry, with a body" step — what ADR-0210 §1 means by *a contribution is a
 * seed row*. Used by beam-ux's own docs seeder and available to any contributing package's, so a
 * contributor writes a seeder, not an entry-creation-and-particle-write pipeline.
 *
 * **Create, never update** (ADR-0209 §11, ADR-0210 §6). The row is site-owned from the moment it exists
 * — that is the entire point of rooting the docs path in data rather than config — so a re-seed after
 * someone has re-worded the page, moved it, or re-rooted the whole subtree must leave every one of those
 * edits alone. Per-package idempotence here means "already present ⇒ nothing to do", not `updateOrCreate`.
 *
 * **Absent beam-ux is a reported skip, not a crash** ({@see canSeed()}). `BeamSeedManifest` takes a
 * class-string, so a contributor's seeder does not load until the seeder runs; when it does run on a
 * headless host with no `beam_ux_entries` table, it no-ops. This follows `BeamMarketServiceProvider`,
 * which depends on beam-ux traits while deliberately not force-booting beam-ux's provider.
 */
trait SeedsEntries
{
    /**
     * Whether entries can be seeded here at all — beam-ux installed AND migrated. A contributing package
     * calls this first and returns quietly when it is false: a headless MCP host running
     * `splicewire:beam:seed` should get a reported skip, never a missing-table exception.
     */
    protected function canSeed(): bool
    {
        return class_exists(BeamUxEntry::class)
            && \Illuminate\Support\Facades\Schema::hasTable('beam_ux_entries');
    }

    /**
     * Create a `page` entry with a body if nothing is registered at `(namespace, slug)` yet, and return
     * it (or the untouched existing row).
     *
     * The body is written through the resolved StorageDriver — the same particle-primary path an editor
     * save takes — and then compiled, so a freshly-seeded page is servable on first boot rather than
     * 404ing until someone runs the backfill. A compile failure is swallowed **here specifically**: a
     * seed runs on hosts with no Node (CI, a container build stage), and taking down `beam:seed` because
     * a page could not be compiled yet would be worse than the doctor reporting an uncompiled page.
     *
     * @param  array<string, mixed>  $attributes  extra columns (parent_id, segment, title, nav_order, …)
     */
    protected function seedPage(
        string $slug,
        string $source,
        array $attributes = [],
        UxFormat $format = UxFormat::Mdx,
        ?string $namespace = null,
        ?string $origin = null,
    ): ?BeamUxEntry {
        if (! $this->canSeed()) {
            return null;
        }

        $existing = BeamUxEntry::query()
            ->where('namespace', $namespace)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            // DOCS-06 (ADR-0215 §2): no longer unconditionally create-once. A pristine package:/disk:
            // row whose origin changed is re-asserted from this source; an edited row is kept and left
            // for docs.diverged; a cms row is untouched. Placement is never re-asserted. No-op pre-migration.
            app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->reassert(
                $existing,
                $attributes['title'] ?? null,
                $source,
                $origin ?? \Splicewire\Beam\Ux\Provenance\Provenance::package('splicewire/laravel-beam-ux'),
            );

            // RETRY REPAIRS (ADR-0225 decision 2). A row a seed left without a Publication Pin (seeded before pages were
            // born published, or by a run that failed while pinning) is pinned here, so re-running the seeder recovers it.
            // baseline() only pins a written body with NO history: a row that has history but no pin is an unpublished
            // draft, and this never publishes it. A no-op where the version store is absent or the row is pinned.
            app(EntryPublication::class)->baseline($existing->refresh());

            return $existing->refresh();
        }

        // ATOMIC, and the create-never-update rule above is exactly why. The row and its body are two
        // writes; without a transaction, anything that throws between them (the write gate refusing, a
        // storage disk unavailable, a schema rejection) leaves a BODYLESS entry row — and then the
        // "already present ⇒ nothing to do" check at the top of this method makes that row permanent. A
        // re-seed sees it, returns it untouched, and the page 404s forever with no error anywhere.
        //
        // Observed exactly this way on `splicewire/www` (beam-docs-satellite ticket 07): the first
        // `splicewire:beam:seed` was refused by the write gate, and the second — with the gate fixed —
        // reported success while quietly skipping the two rows the first run had stranded. A failure that
        // makes the retry a no-op is worse than a failure that leaves nothing behind.
        // BORN PUBLISHED (ADR-0225 decision 2): a seeded page gets its Publication Pin when it is created. Seeders run
        // after `migrate`, so the baseline migration never sees these rows; without this they stayed pin-less, and
        // EP-08's editor refuses to mount where the pin is missing. Refuses with the remedy, before any row is written,
        // on a host without the version store (launch row qitem-20261016214321-d6aa335c).
        $publication = app(EntryPublication::class);
        $publication->requireVersionStore();

        // The create, the body write AND the publication share one transaction: a failure while pinning rolls the row
        // back too, so a failed seed leaves nothing behind and the next run seeds the page from scratch.
        return DB::transaction(function () use ($slug, $format, $namespace, $attributes, $source, $origin, $publication): BeamUxEntry {
            // BORN PUBLISHED, and this is what makes the OTB promise true rather than nearly true.
            // `WorkflowMarkingPublishGate` treats a workflow-managed entry as public only at the
            // published marking, and a freshly-created row's `workflow_marking` is NULL — so on any host
            // with a `page` workflow binding (beam-workflows is a default install), every seeded docs
            // page resolved correctly, compiled correctly, and then 404'd behind the publish gate.
            // Package-seeded content exists precisely to be live on first boot; a contribution that
            // arrives as an invisible draft is ADR-0210 §1 not happening.
            //
            // Overridable, because it sits in the DEFAULTS that `$attributes` merges over: a contributor
            // seeding genuinely draft content passes its own marking, and create-only means a host that
            // later unpublishes the row is never overwritten by a re-seed.
            // DOCS-06: stamp provenance LAST so `origin` + `asserted_hash` are authoritative over
            // $attributes. A seeded row's origin is its package (the caller passes `package:<vendor>`;
            // falls back to this package's seed machinery), and the hash is taken over the source the
            // seed asserts, so a later host edit is detectable by `docs.diverged`. No-ops pre-migration.
            $entry = BeamUxEntry::create(array_merge([
                'slug' => $slug,
                'type' => UxType::Page,
                'format' => $format,
                'namespace' => $namespace,
            ], BeamUxEntry::publishedMarkingAttributes(), $attributes, \Splicewire\Beam\Ux\Provenance\Provenance::stamp(
                $origin ?? \Splicewire\Beam\Ux\Provenance\Provenance::package('splicewire/laravel-beam-ux'),
                $attributes['title'] ?? null,
                $source,
                app(\Splicewire\Beam\Ux\Codec\CodecRegistry::class)->for($format),
            )));

            $written = app(StorageDriverResolver::class)
                ->resolve($entry)
                ->write('', $entry->codec()->encode($source), $entry->namespace);

            if ($written->key !== '') {
                $entry->particle_id = $written->key;
                $entry->save();
            }

            // Pin the written body and compile it at the pinned address: what a reader is served from now on. A failure
            // to pin propagates and rolls the row back (EntryPublication already tolerates a compile failure, returning
            // it rather than throwing).
            $publication->publishWritten($entry->refresh(), 'seeded');

            // A body the entry's storage driver doesn't keep as a particle has no version to pin: it compiles as it always
            // did, and only THAT compile's failure is tolerated (reported by BeamUxArtifactAudit).
            if ($entry->refresh()->getAttribute('published_version') === null) {
                try {
                    app(CompileEntryBody::class)->forEntry($entry, $source, force: true);
                } catch (CompilationFailed) {
                }
            }

            return $entry->refresh();
        });
    }
}
