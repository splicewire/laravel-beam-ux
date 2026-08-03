<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The BeamUx **draft-schema marker** column added additively to `beam_ux_entries` (charter S9,
 * `beamux-build/issues/06`). When a fresh `component` is registered, {@see
 * Splicewire\Beam\Ux\Inference\InferDraftSchema} infers a JSON-Schema from its props and stores it as
 * the entry's `schema_ref` — but marks it a DRAFT so it is editable immediately without pretending to be
 * a finished, authored spec.
 *
 *  - `schema_is_draft` — is the current `schema_ref` an INFERRED draft (true) rather than an author's
 *    deliberate spec (false)? Defaults false: a hand-authored / graduated entry is not a draft. The
 *    inference action is the ONLY writer that sets it true; graduation (a separate authoring act) clears
 *    it. Widgets/validation/refs are never fabricated by inference, so a draft is the honest floor.
 *    Indexed so an authoring surface can list "still-draft" components cheaply.
 *
 * **Migration ordering:** files now carry REAL sequential timestamps. This `170006` alter sorts AFTER the
 * `170000`–`170005` beam-ux migrations, so the base table already exists; the whole set lands after
 * beam-core's `beam_particles` (`2026_08_03_162536`). Shipped PUBLISH-ONLY via the plain provider's
 * {@see ServiceProvider::publishesMigrations()} (`beam-ux-migrations` tag): `vendor:publish` copies this
 * flat file into the host's `database/migrations/` and its `tenant/` twin into `database/migrations/tenant/`,
 * and the HOST runs each pass (central `migrate` + tenant `tenants:migrate`), so the column shape is
 * identical central + tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            // Is the current schema_ref an INFERRED draft (never final until graduated)?
            $table->boolean('schema_is_draft')->default(false)->index()->after('schema_ref');
        });
    }

    public function down(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->dropColumn('schema_is_draft');
        });
    }
};
