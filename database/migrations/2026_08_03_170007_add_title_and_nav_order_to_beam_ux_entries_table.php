<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The BeamUx **host-provided nav labeling** columns added additively to `beam_ux_entries`. Both are
 * ubiquitous entry attributes the package's own {@see Splicewire\Beam\Ux\Containment\NavProjector}
 * already consumes — so they belong in the package, not as host-local patches:
 *
 *  - `title` — a human label for the entry. {@see NavProjector} reads `$entry->title ?? Str::headline(slug)`
 *    (UNGUARDED — the projector assumes the attribute exists, and the model already lists it `$fillable`),
 *    so an entry without an explicit label falls back to a headline of its slug. Nullable: most entries
 *    ride the slug fallback and only authored/nav surfaces set an explicit title.
 *  - `nav_order` — an optional sibling sort key. The projector orders by it WHEN present and falls back to
 *    `slug` otherwise. Nullable so an unordered sitemap is never broken by an orderBy on a missing value.
 *
 * **Migration ordering:** carries a REAL sequential timestamp. This `170007` alter sorts AFTER the
 * `170000`–`170006` beam-ux migrations, so the base table (and its `slug`/`segment` columns) already
 * exist. Shipped PUBLISH-ONLY via {@see ServiceProvider::publishesMigrations()} (`beam-ux-migrations`
 * tag): `vendor:publish` copies this flat file into the host's `database/migrations/` and its `tenant/`
 * twin into `database/migrations/tenant/`, and the HOST runs each pass, so the shape is identical
 * central + tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            // Human label for nav/authoring surfaces; NavProjector falls back to headline(slug) when null.
            $table->string('title')->nullable()->after('slug');
            // Optional sibling sort key; NavProjector orders by it when present, else by slug.
            $table->integer('nav_order')->nullable()->after('segment');
        });
    }

    public function down(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->dropColumn(['title', 'nav_order']);
        });
    }
};
