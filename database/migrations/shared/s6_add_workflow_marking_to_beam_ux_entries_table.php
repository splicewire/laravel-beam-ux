<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
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
 * **Shared-migration ordering (S1 footgun):** shared migrations sort LEXICALLY (no timestamp). The
 * `s6_` prefix keeps this alter AFTER `create_`/`s1_`/`s2_`/`s3_a`/`s3_b` (`create_` < `s1_` < … < `s6_`),
 * so the base table + its earlier aspects already exist. Runs in BOTH the central `migrate` and tenant
 * `tenants:migrate` passes via {@see BeamUxServiceProvider::bootMigrations()}, so the column shape is
 * identical central + tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
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
