<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * The BeamUx authoring-entry table. A deliberately generic, flat row that **has-a** a versioned
 * {@see BeamParticle} body via `particle_id` — the particle table (`beam_particles`, beam-core) holds
 * the migrate-on-read content; this row holds the queryable authoring envelope, plus every aspect axis
 * (type/format, storage placement, containment, workflow marking, nav labeling, editability tier) beam-ux
 * has grown since. Squashed pre-prod (no deployed data to preserve migration history for) from what was
 * previously a create + 7 additive ALTERs into one clean create — see git history for the per-aspect
 * rationale if it's ever needed again.
 *
 * SHARED (central + every tenant): published to the single `database/migrations/shared/` destination.
 * The `Schema::hasTable()` dup-guard below is there so a host that migrates BOTH passes into ONE schema
 * (the shared-test-DB harness) doesn't re-create the table; production separates schemas, so the guard
 * is false.
 *
 * Shipped as a publish-only spatie/laravel-package-tools stub (`runsMigrations` FALSE): the package
 * publishes this timestamp-less `.php.stub` via `configurePackage()`'s `->hasMigrations([...])`
 * (`vendor:publish --tag=beam-ux-migrations`), which re-stamps + sequences a single copy into the host's
 * `database/migrations/shared/` destination at install time — beam-tenancy's
 * `registerSharedMigrationsPath()` runs that directory in both the central `migrate` pass and Stancl's
 * tenant pass. The package never `loadMigrationsFrom`'s or runs migrations at runtime.
 *
 * Residency is `context-following` (default): an entry lives wherever it is authored. `realm` is the
 * containment root (defaults to the public `site` realm); `sitemap_id`/`parent_id`/`segment` are the
 * adjacency-list containment tree (decoupled from `namespace`, which is disk-only build grouping — the
 * "two trees"). `format`/`body_style` are the body-language axis (sibling to `type`). `placement_ref`/
 * `driver_ref` are the S2 storage precedence refs. `workflow_marking`/`workflow_version` make the entry
 * an OPTIONAL subject of the free-tier `laravel-beam-workflows` engine. `schema_is_draft` marks an
 * inferred (vs. authored) `schema_ref`. `composable` gates the WYSIWYG edit chrome (free composition vs.
 * a fixed template around a sealed behavior island).
 */
return new class extends Migration
{
    public function up(): void
    {
        // SHARED-table dup-guard: the shared-test-DB harness may migrate the same shared/ file via
        // BOTH the central and tenant passes into ONE `public` schema, so it may already have created
        // this table. Production targets separate schemas, so this guard is simply false there.
        if (Schema::hasTable('beam_ux_entries')) {
            return;
        }

        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The has-a body: an FK to the generic beam_particles row (beam-core). Not a DB-level
            // constrained FK — the particle table name rides beam-core's prefix seam and may vary per
            // host — so it is a plain indexed uuid the `particle()` relation resolves.
            $table->uuid('particle_id')->nullable()->index();

            // Authoring envelope. schema_ref is the declared schema binding of the body; schema_is_draft
            // marks it an INFERRED draft (vs. an author's deliberate spec) — the ONLY writer that sets it
            // true is the inference action; graduation clears it. facade_ref is nullable (single-rendering
            // entries carry none; a multi-rendered canonical resolves its facade lens invisibly, ADR-0155).
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false)->index();
            $table->string('facade_ref')->nullable();

            // type ∈ {layout, template, page, component}. format is the sibling body-language/codec axis
            // (ADR-0164) — which codec compiles/renders the body and which extension it materializes to.
            // body_style is a tsx-codec-local flavor, meaningless for other formats. composable gates
            // whether the body is free structural composition or a fixed template (default true —
            // backward-compatible; a host demotes a behavior realm to false at seed time).
            $table->string('type')->index();
            $table->string('format')->default(UxFormat::Tsx->value)->index();
            $table->string('body_style')->nullable();
            $table->boolean('composable')->default(true);

            // namespace is the dot-nestable BUILD grouping (disk placement only, NOT URL/taxonomy).
            // placement_ref/driver_ref are the S2 per-entry storage precedence refs (fall through to the
            // namespace map, then the default `Stacked(Particle, Disk)`).
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();

            // Residency: context-following by default — the entry lives wherever authored.
            $table->string('residency_mode')->default('context-following')->index();

            // Containment: the organization spine deriving the entry's PUBLIC URL — decoupled from
            // `namespace` (the "two trees"). realm is the public route root (defaults to `site`).
            // sitemap_id/parent_id are plain indexed uuids (not DB-constrained FKs, portable central +
            // tenant) resolved via their model relations. segment composes DOWN the tree (bare/`./` is
            // parent-relative, `/` resets to the realm/sitemap root). nav_order is an optional sibling
            // sort key the NavProjector orders by when present, falling back to slug otherwise.
            $table->string('realm')->default('site')->index();
            $table->uuid('sitemap_id')->nullable()->index();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->integer('nav_order')->nullable();

            // Workflow: an OPTIONAL subject of the free-tier laravel-beam-workflows engine. NULL marking
            // = unmanaged / at-initial; the published-marking gate reads it so an unmanaged entry stays
            // public by default. workflow_version pins the definition version on first transition.
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();

            $table->timestamps();

            // One entry per slug within a build namespace.
            $table->unique(['namespace', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beam_ux_entries');
    }
};
