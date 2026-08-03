<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Models\BeamParticle;

/**
 * The BeamUx authoring-entry table (charter S0). A deliberately generic, flat row that **has-a** a
 * versioned {@see BeamParticle} body via `particle_id` — the particle table
 * (`beam_particles`, beam-core) holds the migrate-on-read content; this row holds the queryable
 * authoring envelope.
 *
 * **TENANT TWIN.** Identical DDL to the flat central copy at `../2026_08_03_170000_create_beam_ux_entries_table.php`.
 * The `Schema::hasTable()` dup-guard below is there so a host that migrates BOTH passes into ONE schema (the
 * shared-test-DB harness) doesn't re-create the table; production separates schemas, so the guard is false.
 *
 * **Ubiquitous shape (charter §Q7).** This migration ships PUBLISH-ONLY via the plain provider's native
 * {@see ServiceProvider::publishesMigrations()} under the `beam-ux-migrations` tag:
 * `vendor:publish --tag=beam-ux-migrations` copies the flat central file into the host's `database/migrations/`
 * (central pass) and this `tenant/` twin into `database/migrations/tenant/` (Stancl tenant pass), and the
 * HOST runs each pass. The package no longer `loadMigrationsFrom`'s or runs migrations at runtime.
 * `beam_ux_entries` thus exists identically in central and every tenant. Residency is `context-following`
 * (default): an entry lives wherever it is authored; only the central path is exercised for now.
 *
 * **S0 columns only — the authoring envelope.** The optional aspects (format, file placement,
 * containment/route, workflow, taxonomy) are additive columns the later charter steps add; they are
 * deliberately absent here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // TENANT TWIN dup-guard: the shared-test-DB harness migrates BOTH the central and tenant
        // passes into ONE `public` schema, so the central pass may already have created this table.
        // Production targets separate schemas, so this guard is simply false there.
        if (Schema::hasTable('beam_ux_entries')) {
            return;
        }

        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The has-a body: an FK to the generic beam_particles row (beam-core). Not a DB-level
            // constrained FK — the particle table name rides beam-core's prefix seam and may vary per
            // host — so it is a plain indexed uuid the `particle()` relation resolves.
            $table->uuid('particle_id')->nullable()->index();

            // Authoring envelope (S0). schema_ref is the declared schema binding of the body; facade_ref
            // is nullable (single-rendering entries carry none; a multi-rendered canonical resolves its
            // facade lens invisibly, ADR-0155). type ∈ {layout, template, page, component}. namespace is
            // the dot-nestable BUILD grouping (disk placement only, NOT URL/taxonomy).
            $table->string('slug')->index();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('namespace')->nullable()->index();

            // Residency (charter §Q7): context-following by default — the entry lives wherever authored.
            $table->string('residency_mode')->default('context-following')->index();

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
