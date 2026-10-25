<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ADR-0229 d8: published declaration only; drafts remain in versioned page settings. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_entries') && ! Schema::hasColumn('beam_ux_entries', 'record_page')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->json('record_page')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('beam_ux_entries') && Schema::hasColumn('beam_ux_entries', 'record_page')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropColumn('record_page'));
        }
    }
};
