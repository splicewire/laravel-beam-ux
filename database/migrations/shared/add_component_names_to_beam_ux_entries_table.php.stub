<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** No Authored rows exist live before EP-20 lands; nullable reservations need no backfill.
 * Names are case-insensitive unique. Soft deletion releases the reservation on every driver. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_entries') || Schema::hasColumn('beam_ux_entries', 'component_name')) {
            return;
        }
        Schema::table('beam_ux_entries', function (Blueprint $table): void {
            $table->string('component_name')->nullable();
            $table->string('published_component_name')->nullable();
        });
        $connection = Schema::getConnection();
        if (in_array($connection->getDriverName(), ['sqlite', 'pgsql'], true)) {
            $connection->statement('CREATE UNIQUE INDEX beam_ux_component_name_unique ON beam_ux_entries (realm, LOWER(component_name))');
        } else {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->unique(['realm', 'component_name'], 'beam_ux_component_name_unique'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('beam_ux_entries') && Schema::hasColumn('beam_ux_entries', 'component_name')) {
            Schema::table('beam_ux_entries', function (Blueprint $table): void {
                if (Schema::getConnection()->getDriverName() === 'pgsql') {
                    $table->dropIndex('beam_ux_component_name_unique');
                } else {
                    $table->dropUnique('beam_ux_component_name_unique');
                }
                $table->dropColumn(['component_name', 'published_component_name']);
            });
        }
    }
};
