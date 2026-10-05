<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DOCS-06 (DM2, ADR-0215): provenance. `origin` records WHERE a row came from — `cms` (authored in
// the editor), `disk:<relative>` (a host file), or `package:<vendor/name>` (a package stub).
// `asserted_hash` is the hash of the title+body the origin last wrote, so a later audit can tell a
// pristine row from an edited one. Both nullable, so a host that has not migrated — and any row
// predating this — is unaffected until its origin stamps it. An ALTER beside the create for the same
// reason as the siblings: editing the create stub reaches nothing already migrated.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('beam_ux_entries', 'origin')) {
            Schema::table('beam_ux_entries', function (Blueprint $table) {
                $table->string('origin')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('beam_ux_entries', 'asserted_hash')) {
            Schema::table('beam_ux_entries', function (Blueprint $table) {
                $table->string('asserted_hash')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('beam_ux_entries', 'asserted_hash')) {
            Schema::table('beam_ux_entries', function (Blueprint $table) {
                $table->dropColumn('asserted_hash');
            });
        }

        if (Schema::hasColumn('beam_ux_entries', 'origin')) {
            Schema::table('beam_ux_entries', function (Blueprint $table) {
                $table->dropColumn('origin');
            });
        }
    }
};
