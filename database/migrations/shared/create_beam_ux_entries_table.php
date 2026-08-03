<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The BeamUx authoring-entry table (charter S0). A deliberately generic, flat row that **has-a** a
 * versioned {@see \Splicewire\Beam\Models\BeamParticle} body via `particle_id` — the particle table
 * (`beam_particles`, beam-core) holds the migrate-on-read content; this row holds the queryable
 * authoring envelope.
 *
 * **Ubiquitous shape (charter §Q7).** This migration lives in beam-ux's `database/migrations/shared`
 * dir, registered by {@see \Splicewire\Beam\Ux\BeamUxServiceProvider::bootMigrations()} into BOTH the
 * central `migrate` pass ({@see \Illuminate\Support\ServiceProvider::loadMigrationsFrom()}) AND the
 * tenant `tenants:migrate` pass (Stancl's `tenancy.migration_parameters.--path` array), so
 * `beam_ux_entries` exists identically in central and every tenant. Residency is `context-following`
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
