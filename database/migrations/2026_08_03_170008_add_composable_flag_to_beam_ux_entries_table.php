<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The BeamUx **editability-tier** column added additively to `beam_ux_entries` (beam Model-B ticket 14).
 *
 * `composable` declares whether an entry's BODY is free structural composition (drop/rearrange/edit
 * blocks in the Puck window) or a FIXED template. Content-realm entries (`site`, `account`) are fully
 * composable; app/behavior-realm entries (`auth`, `studio`, `checkout`, …) exist for routing / nav /
 * permission but their body is a fixed template around a sealed behavior island (the login/register
 * Fortify form, the studio DAW), NOT free composition. The authoring gate/projection reads this flag to
 * decide whether to offer the WYSIWYG `window` edit chrome for an entry at all.
 *
 * DEFAULT `true` — backward-compatible: every existing entry stays composable exactly as before this
 * column landed. A host demotes a behavior realm to `composable = false` at seed time (the host owns the
 * realm→default policy; see the satellite's `RealmRegistry`).
 *
 * **Migration ordering:** carries a REAL sequential timestamp. This `170008` alter sorts AFTER the
 * `170000`–`170007` beam-ux migrations, so the base table already exists. Shipped PUBLISH-ONLY via
 * {@see Illuminate\Support\ServiceProvider::publishesMigrations()} (`beam-ux-migrations` tag): the flat
 * file publishes into the host's `database/migrations/` (central pass) and its `tenant/` twin into
 * `database/migrations/tenant/` (Stancl tenant pass), so the shape is identical central + tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            // Editability tier: is this entry's body free composition (true) or a fixed template (false)?
            // Default true so every pre-existing entry stays composable (backward-compatible).
            $table->boolean('composable')->default(true)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->dropColumn('composable');
        });
    }
};
