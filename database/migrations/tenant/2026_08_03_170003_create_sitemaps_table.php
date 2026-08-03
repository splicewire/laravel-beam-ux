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
 * **Migration ordering:** files now carry REAL sequential timestamps. This `170003` sitemaps create sorts
 * before the `170004` containment alter that FKs `beam_ux_entries.sitemap_id` to it (so the FK target
 * exists first), and after the `170000`–`170002` beam-ux migrations; the whole set lands after beam-core's
 * `beam_particles` (`2026_08_03_162536`). Shipped PUBLISH-ONLY via the plain provider's
 * {@see ServiceProvider::publishesMigrations()} (`beam-ux-migrations` tag): `vendor:publish` copies the
 * flat central file into the host's `database/migrations/` and this `tenant/` twin into
 * `database/migrations/tenant/`, and the HOST runs each pass, so the table exists identically central +
 * every tenant (residency is context-following).
 *
 * **TENANT TWIN.** Identical DDL to the flat central copy. The `Schema::hasTable()` dup-guard below is
 * there so a host that migrates BOTH passes into ONE schema (the shared-test-DB harness) doesn't re-create
 * the table; production separates schemas, so the guard is false.
 */
return new class extends Migration
{
    public function up(): void
    {
        // TENANT TWIN dup-guard: the shared-test-DB harness migrates BOTH the central and tenant
        // passes into ONE `public` schema, so the central pass may already have created this table.
        // Production targets separate schemas, so this guard is simply false there.
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
