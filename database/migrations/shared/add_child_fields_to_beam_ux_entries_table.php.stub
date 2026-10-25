<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0233 d4: the Page Fields a parent page declares for its child pages, as the name of a Data class the host
 * registers in `beam.ux.page_fields`. The published copy only; drafts live in the particle's versioned meta (ADR-0232
 * d1). Additive and nullable, so it changes no existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_entries') && ! Schema::hasColumn('beam_ux_entries', 'child_fields')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->string('child_fields')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('beam_ux_entries') && Schema::hasColumn('beam_ux_entries', 'child_fields')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropColumn('child_fields'));
        }
    }
};
