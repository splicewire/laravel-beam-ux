<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Storage\StorageDriver;
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
 * **Migration ordering:** files now carry REAL sequential timestamps, so this alter's `170002` prefix
 * sorts it AFTER the `170000` create and the `170001` type-axes alter; the whole beam-ux set lands after
 * beam-core's `beam_particles` (`2026_08_03_162536`). Shipped PUBLISH-ONLY via the plain provider's
 * {@see ServiceProvider::publishesMigrations()} (`beam-ux-migrations` tag): `vendor:publish` copies the
 * flat central file into the host's `database/migrations/` and this `tenant/` twin into
 * `database/migrations/tenant/`, and the HOST runs each pass, so the columns exist identically central + tenant.
 *
 * **TENANT TWIN.** Identical DDL to the flat central copy. The `Schema::hasColumn()` dup-guard below is
 * there so a host that migrates BOTH passes into ONE schema (the shared-test-DB harness) doesn't re-alter;
 * production separates schemas, so the guard is false.
 */
return new class extends Migration
{
    public function up(): void
    {
        // TENANT TWIN dup-guard: the shared-test-DB harness runs both passes into one `public`
        // schema, so the columns may already exist from the central pass. Production separates schemas.
        if (Schema::hasColumn('beam_ux_entries', 'placement_ref')) {
            return;
        }

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
