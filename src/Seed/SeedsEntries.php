<?php

namespace Splicewire\Beam\Ux\Seed;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
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
    ): ?BeamUxEntry {
        if (! $this->canSeed()) {
            return null;
        }

        $existing = BeamUxEntry::query()
            ->where('namespace', $namespace)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            return $existing;
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
        $entry = DB::transaction(function () use ($slug, $format, $namespace, $attributes, $source): BeamUxEntry {
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
            $entry = BeamUxEntry::create(array_merge([
                'slug' => $slug,
                'type' => UxType::Page,
                'format' => $format,
                'namespace' => $namespace,
            ], BeamUxEntry::publishedMarkingAttributes(), $attributes));

            $written = app(StorageDriverResolver::class)
                ->resolve($entry)
                ->write('', $entry->codec()->encode($source), $entry->namespace);

            if ($written->key !== '') {
                $entry->particle_id = $written->key;
                $entry->save();
            }

            return $entry;
        });

        try {
            app(CompileEntryBody::class)->forEntry($entry->refresh(), $source, force: true);
        } catch (CompilationFailed) {
            // Reported by BeamUxArtifactAudit; see the docblock above for why this one is not fatal.
        }

        return $entry->refresh();
    }
}
