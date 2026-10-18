<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Release's provenance (owner ruling 2026-10-18; launch ticket 00 `5dd27af98`). `direct` is a Release of one made by
 * the editor's Publish or the Workflow tab's Take-down, decided on the single publish ability at every step; `built`
 * is a Release assembled member by member. An ALTER beside the create, because editing a `create_*` stub reaches
 * nothing already migrated. Every existing Release is `built`. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_releases') && ! Schema::hasColumn('beam_ux_releases', 'origin')) {
            Schema::table('beam_ux_releases', function (Blueprint $table) {
                $table->string('origin')->default('built');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('beam_ux_releases') && Schema::hasColumn('beam_ux_releases', 'origin')) {
            Schema::table('beam_ux_releases', function (Blueprint $table) {
                $table->dropColumn('origin');
            });
        }
    }
};
