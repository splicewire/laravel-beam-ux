<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\Sitemap;

/**
 * The BeamUx **containment aspect** columns added additively to `beam_ux_entries` (charter S3,
 * ADR-0165). The organization spine: a rooted containment tree whose position derives the entry's
 * PUBLIC URL — decoupled from `namespace` (that is disk-only, S2; the "two trees").
 *
 *  - `realm`      — the realm this entry's public route roots under (defaults to the public `site`
 *    realm added to the beam RealmRegistry in S3).
 *  - `sitemap_id` — the {@see Sitemap} this entry belongs to. Nullable at the
 *    DB level (the FK "holds the door open" for deferred multiplicity); a host provisions the one
 *    default sitemap and the model defaults new entries onto it.
 *  - `parent_id`  — the containing `beam_ux_entries` node (adjacency list; the strict single home). Null
 *    = a top-level node directly under the sitemap root.
 *  - `segment`    — this node's own path piece. Composes DOWN the tree: bare/`./segment` is
 *    parent-relative (folder semantics); `/segment` resets to the realm/sitemap root.
 *
 * **Shared-migration ordering (S1 footgun):** shared migrations sort LEXICALLY (no timestamp). The
 * `s3_b_` prefix keeps this alter AFTER `s3_a_create_sitemaps_table` (so the FK target exists) and after
 * `create_`/`s1_`/`s2_`. Not a DB-constrained FK — the sitemaps table rides no host prefix seam here,
 * but the beam-ux table shape stays portable central + tenant, so `sitemap_id` is a plain indexed uuid
 * the {@see BeamUxEntry::sitemap()} relation resolves. Runs in BOTH the
 * central `migrate` and tenant `tenants:migrate` passes via {@see BeamUxServiceProvider::bootMigrations()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            // The realm this entry's public route roots under (ADR-0165). Public content → `site`.
            $table->string('realm')->default('site')->index()->after('residency_mode');

            // The containing sitemap (deferred multiplicity — FK-shaped, model-defaulted).
            $table->uuid('sitemap_id')->nullable()->index()->after('realm');

            // Adjacency parent (strict single home). Null = top-level under the sitemap root.
            $table->uuid('parent_id')->nullable()->index()->after('sitemap_id');

            // This node's own path piece — composes down the tree (`./` parent-relative, `/` root-absolute).
            $table->string('segment')->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->dropColumn(['realm', 'sitemap_id', 'parent_id', 'segment']);
        });
    }
};
