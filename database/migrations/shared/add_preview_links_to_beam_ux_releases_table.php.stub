<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Release's Preview Links (ADR-0232 d8): whether they are on, and the secret they are signed with, which the Release
 * holds so rotating it ends every link at once. An ALTER beside the create, for the same reason as `origin`. Every
 * existing Release starts with links off. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_releases')) {
            return;
        }
        if (! Schema::hasColumn('beam_ux_releases', 'preview_links_enabled')) {
            Schema::table('beam_ux_releases', fn (Blueprint $table) => $table->boolean('preview_links_enabled')->default(false));
        }
        if (! Schema::hasColumn('beam_ux_releases', 'preview_link_secret')) {
            Schema::table('beam_ux_releases', fn (Blueprint $table) => $table->string('preview_link_secret', 64)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['preview_links_enabled', 'preview_link_secret'] as $column) {
            if (Schema::hasTable('beam_ux_releases') && Schema::hasColumn('beam_ux_releases', $column)) {
                Schema::table('beam_ux_releases', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
