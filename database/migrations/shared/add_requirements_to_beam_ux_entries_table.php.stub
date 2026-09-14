<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('beam_ux_entries', 'requirements')) {
            Schema::table('beam_ux_entries', function (Blueprint $table) {
                $table->json('requirements')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('beam_ux_entries', 'requirements')) {
            Schema::table('beam_ux_entries', function (Blueprint $table) {
                $table->dropColumn('requirements');
            });
        }
    }
};
