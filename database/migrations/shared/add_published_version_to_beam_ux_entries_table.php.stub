<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PUBLICATION PIN — one nullable column, `published_version`, holding the id of the
 * `rushing/laravel-versioning` {@see \Rushing\Versioning\Models\Version} row the entry's PUBLIC
 * artifact was compiled from.
 *
 * It is a SECOND PIN into the ONE version store, never a second store. `beam_particles.head_version`
 * already pins the working HEAD — the newest recorded snapshot of the body an author is editing; this
 * pins the one a READER is served. Draft and published are therefore the same history with two
 * pointers into it, and "a draft is pending" is the pointers disagreeing, not a flag anyone has to
 * keep true.
 *
 * ⚠️ **Not `workflow_marking`, which answers a different question.** That column (S6) governs whether
 * the ENTRY is publicly visible at all — an unpublished marking prunes the node and its subtree from
 * nav, sitemap and routing. The journey this pin serves is the opposite: the page stays visible and
 * keeps serving its last published body while a draft exists. Overloading the marking would have hidden
 * the page instead of holding it steady, so the two aspects stay orthogonal and compose.
 *
 * Additive by construction: this is the ALTER half of the pair. `create_beam_ux_entries_table` carries
 * the same column for a FRESH database — editing a `create_*` stub reaches nothing already migrated
 * (the estate trap of the same name), so a host that migrated before this pass gets the column here and
 * a host that migrates after gets it there, and this file no-ops for the latter.
 *
 * The column is OPTIONAL everywhere it is read: every consumer resolves it through
 * `getAttribute('published_version')`, which is null on a host that has not run this migration, and a
 * null pin degrades to exactly the pre-pin behaviour (the artifact address keys on the particle write,
 * and every save publishes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_entries') || Schema::hasColumn('beam_ux_entries', 'published_version')) {
            return;
        }

        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('published_version')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('beam_ux_entries') && Schema::hasColumn('beam_ux_entries', 'published_version')) {
            Schema::table('beam_ux_entries', function (Blueprint $table) {
                $table->dropColumn('published_version');
            });
        }
    }
};
