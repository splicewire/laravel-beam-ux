<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ADR-0231: additive declaration only; existing component rows remain source without a rewrite. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_entries') && ! Schema::hasColumn('beam_ux_entries', 'component_kind')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->string('component_kind')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('beam_ux_entries') && Schema::hasColumn('beam_ux_entries', 'component_kind')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropColumn('component_kind'));
        }
    }
};
