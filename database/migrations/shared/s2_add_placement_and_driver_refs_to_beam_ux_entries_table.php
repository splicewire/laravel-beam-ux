<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Placement\FilePlacement;

/**
 * The BeamUx **storage aspect** columns added additively to `beam_ux_entries` (charter S2, ADR-0165).
 * The two per-entry precedence refs the S2 resolvers read FIRST (before the per-namespace map, before
 * the default):
 *
 *  - `placement_ref` — names the {@see FilePlacement} strategy this entry
 *    files under (e.g. `date-partitioned`); nullable → falls through to the namespace map / default.
 *  - `driver_ref` — names the free-beam-core {@see StorageDriver} this entry
 *    persists through; nullable → falls through to the default `Stacked(Particle, Disk)`.
 *
 * **Shared-migration ordering (S1 footgun):** shared migrations carry NO timestamp and sort
 * LEXICALLY, so the `s2_` prefix keeps this alter AFTER `create_beam_ux_entries_table` and `s1_…`. Runs
 * in BOTH the central `migrate` and tenant `tenants:migrate` passes via
 * {@see BeamUxServiceProvider::bootMigrations()}, so the columns exist identically central + tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            // per-entry FilePlacement strategy ref (S2 precedence rung 1). Disk grouping only (ADR-0165).
            $table->string('placement_ref')->nullable()->after('namespace');

            // per-entry free-beam-core StorageDriver ref (S2 precedence rung 1).
            $table->string('driver_ref')->nullable()->after('placement_ref');
        });
    }

    public function down(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->dropColumn(['placement_ref', 'driver_ref']);
        });
    }
};
