<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The chrome axes were originally added to the create stub. Hosts that published it earlier
// need an additive upgrade; fresh and partially upgraded hosts must retain their existing values.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_entries')) {
            return;
        }

        foreach (['layout', 'template', 'nav_group'] as $column) {
            if (! Schema::hasColumn('beam_ux_entries', $column)) {
                Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->string($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        // Forward-only additive repair: a later rollback cannot tell which of these columns were
        // owned by the host's original create stub. Retain them rather than drop host data.
    }
};
