<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * config-sections ticket 08: a non-entry member records the RESOLVED residency it was placed under — the realm plus the
 * resolved connection identity (a tenant id, or the resolved database/schema under the `central` alias). Publish and
 * rollback re-check it against the publishing context, so a later context switch cannot carry a config override across a
 * realm boundary (a central override into a tenant Release, or the reverse).
 *
 * Nullable: an entry member resolves its residency from its entry's connection each time and records none here. ALTER
 * beside the create stub (which carries the same column for a fresh install). Idempotent: a second run, or a fresh
 * install whose create stub already has the column, changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_release_members') || Schema::hasColumn('beam_ux_release_members', 'member_residency')) {
            return;
        }

        Schema::table('beam_ux_release_members', function (Blueprint $table) {
            $table->string('member_residency')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('beam_ux_release_members') || ! Schema::hasColumn('beam_ux_release_members', 'member_residency')) {
            return;
        }

        Schema::table('beam_ux_release_members', function (Blueprint $table) {
            $table->dropColumn('member_residency');
        });
    }
};
