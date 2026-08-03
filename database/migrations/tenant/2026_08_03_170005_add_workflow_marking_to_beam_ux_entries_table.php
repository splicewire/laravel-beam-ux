<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The BeamUx **workflow aspect** columns added additively to `beam_ux_entries` (charter S6). The entry
 * becomes an OPTIONAL workflow subject of the free-tier `laravel-beam-workflows` engine (ADR-0092: the
 * binding + persisted marking are paid `splicewire/*`, the engine they ride is free). No new state
 * machine — the entry implements the package's `WorkflowManaged` contract and its marking projects onto
 * a column here, exactly as every other managed record does (the package's "marking rides the host's
 * typed port envelope" design).
 *
 *  - `workflow_marking` — the entry's current marking (its single-place lifecycle state). NULL means
 *    "no marking pinned yet": an entry with no resolved binding is UNMANAGED and never acquires one, so
 *    NULL is the honest unmanaged/at-initial value. The published-marking gate reads this column: a
 *    managed entry is public only while its marking sits at a published place ({@see
 *    BeamUxEntry::MARKING_PUBLISHED}); an unmanaged entry (no binding) has no workflow and stays public
 *    by default — so this replaces S4's `AlwaysPublishedGate` stub without hiding unmanaged content.
 *  - `workflow_version` — the definition VERSION this entry is pinned to (the package pins it on first
 *    transition so its graph never shifts under it). NULL until a managed entry first transitions.
 *
 * **Migration ordering:** files now carry REAL sequential timestamps. This `170005` alter sorts AFTER the
 * `170000`–`170004` beam-ux migrations, so the base table + its earlier aspects already exist; the whole
 * set lands after beam-core's `beam_particles` (`2026_08_03_162536`). Shipped PUBLISH-ONLY via the plain
 * provider's {@see ServiceProvider::publishesMigrations()} (`beam-ux-migrations` tag): `vendor:publish`
 * copies the flat central file into the host's `database/migrations/` and this `tenant/` twin into
 * `database/migrations/tenant/`, and the HOST runs each pass (central `migrate` + tenant `tenants:migrate`),
 * so the column shape is identical central + tenant.
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
        if (Schema::hasColumn('beam_ux_entries', 'workflow_marking')) {
            return;
        }

        Schema::table('beam_ux_entries', function (Blueprint $table) {
            // The entry's current workflow marking (single-place lifecycle state). NULL = unmanaged /
            // at-initial. The published-marking gate reads this column.
            $table->string('workflow_marking')->nullable()->index()->after('segment');

            // The definition version this entry is pinned to (the package records it on first move).
            $table->string('workflow_version')->nullable()->after('workflow_marking');
        });
    }

    public function down(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->dropColumn(['workflow_marking', 'workflow_version']);
        });
    }
};
