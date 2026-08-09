<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Models\Sitemap;

/**
 * The `sitemaps` table (beamux-entry-charter S3, ADR-0165) — a first-class rooted **containment tree**
 * owned by a site/realm. Each row is one sitemap; a `BeamUxEntry` belongs to one via `sitemap_id`, and
 * the URL of every entry is inherited by composing `segment` down the tree from this sitemap's root.
 *
 * **Default: one auto-provisioned per site** (the entry's `sitemap_id` FK defaults to it). MULTIPLICITY
 * (many sitemaps per site, per-tenant sitemaps) is DEFERRED — the door is held open by the shape (a
 * table + a `realm` key + a `default` marker), not by a built feature. {@see Sitemap} is the model.
 *
 * Shipped as a publish-only spatie/laravel-package-tools stub (`runsMigrations` FALSE), registered
 * alongside the other beam-ux stubs via `configurePackage()`'s `->hasMigrations([...])`. `vendor:publish
 * --tag=beam-ux-migrations` re-stamps + sequences the flat central file into the host's
 * the single `database/migrations/shared/` destination at install time, so
 * the table exists identically central + every tenant (residency is context-following). The declared
 * `->hasMigrations([...])` order keeps this create ahead of the containment alter that FKs
 * `beam_ux_entries.sitemap_id` to it.
 *
 * SHARED (central + every tenant): published to the single `database/migrations/shared/` destination. The `Schema::hasTable()` dup-guard below is
 * there so a host that migrates BOTH passes into ONE schema (the shared-test-DB harness) doesn't re-create
 * the table; production separates schemas, so the guard is false.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SHARED-table dup-guard: the shared-test-DB harness may migrate the same shared/ file via
        // BOTH the central and tenant passes into ONE `public` schema, so it may already have created
        // this table. Production targets separate schemas, so this guard is simply false there.
        if (Schema::hasTable('sitemaps')) {
            return;
        }

        Schema::create('sitemaps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The site/realm this sitemap roots (ADR-0165). Defaults to the public `site` realm — the
            // realm added to the beam RealmRegistry in S3. A future multi-realm host keys off this.
            $table->string('realm')->default('site')->index();

            // Human label (e.g. "Public site"). Not the URL root — the root is the empty path.
            $table->string('name')->nullable();

            // The one-per-site default marker. Exactly one sitemap per realm is the default the entry FK
            // resolves to. The single-default invariant is upheld by the provisioner (find-or-create),
            // NOT a composite unique on `is_default` — that would forbid a second NON-default sitemap and
            // slam the multiplicity door this shape deliberately holds open.
            $table->boolean('is_default')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sitemaps');
    }
};
