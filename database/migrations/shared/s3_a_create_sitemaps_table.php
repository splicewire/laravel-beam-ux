<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
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
 * **Shared-migration ordering (S1 footgun):** shared migrations carry NO timestamp and sort LEXICALLY.
 * This `s3_a_` create MUST sort before the `s3_b_` alter that FKs `beam_ux_entries.sitemap_id` to it
 * (`a` < `b`), and after `create_` / `s1_` / `s2_`. Registered into BOTH the central `migrate` and
 * tenant `tenants:migrate` passes via {@see BeamUxServiceProvider::bootMigrations()}, so the table
 * exists identically central + every tenant (residency is context-following).
 */
return new class extends Migration
{
    public function up(): void
    {
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
