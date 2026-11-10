<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release members have kinds (config-sections ticket 04; ADR-0243 amending ADR-0226). An ALTER beside the create, because
 * editing a `create_*` stub reaches nothing already migrated (the create stub carries the same shape for a fresh install).
 *
 * - `member_kind` (default `entry`) and `member_ref`, the kind's own address for its subject. Every existing member is an
 *   entry, so `member_ref` is backfilled from `entry_id`.
 * - `member_ref` stays nullable: a writer that predates kinds (an older package, a host's own insert, a fixture) still
 *   writes `entry_id` alone, and that row is an `entry` member whose address is its `entry_id`
 *   (`BeamUxReleaseMember::ref()`). Every other kind always writes its ref.
 * - `entry_id` becomes nullable: a member of another kind (a config override, ticket 08) has no entry. The unique
 *   `(release_id, entry_id)` stays, since NULLs never collide, and `(release_id, member_kind, member_ref)` joins it.
 *
 * Making a column nullable rebuilds the table on SQLite (Laravel's native `change()`), so the backfill runs first and the
 * unique index is added after. Idempotent: a second run, or a fresh install whose create stub already has the shape,
 * changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_release_members') || Schema::hasColumn('beam_ux_release_members', 'member_kind')) {
            return;
        }

        Schema::table('beam_ux_release_members', function (Blueprint $table) {
            $table->string('member_kind')->default('entry');
            $table->string('member_ref')->nullable();
        });

        DB::table('beam_ux_release_members')->whereNull('member_ref')->update([
            'member_kind' => 'entry',
            'member_ref' => DB::raw('entry_id'),
        ]);

        Schema::table('beam_ux_release_members', function (Blueprint $table) {
            $table->uuid('entry_id')->nullable()->change();
            $table->unique(['release_id', 'member_kind', 'member_ref']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('beam_ux_release_members') || ! Schema::hasColumn('beam_ux_release_members', 'member_kind')) {
            return;
        }

        // Only entry members can return to the entry-only shape; anything else has no `entry_id` to keep.
        DB::table('beam_ux_release_members')->where('member_kind', '!=', 'entry')->delete();

        Schema::table('beam_ux_release_members', function (Blueprint $table) {
            $table->dropUnique(['release_id', 'member_kind', 'member_ref']);
        });
        Schema::table('beam_ux_release_members', function (Blueprint $table) {
            $table->dropColumn(['member_kind', 'member_ref']);
        });
        Schema::table('beam_ux_release_members', function (Blueprint $table) {
            $table->uuid('entry_id')->nullable(false)->change();
        });
    }
};
