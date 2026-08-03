<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * The BeamUx **aspect axes** added additively to `beam_ux_entries` (charter S1, ADR-0164). S0 created
 * the base envelope (incl. `type`); this alter adds the two S1 aspect columns without touching S0's
 * create — the charter's "aspect columns added additively by later steps" discipline.
 *
 *  - `format` — the **body-language axis** (sibling to `type`, NOT a fifth kind): which codec
 *    compiles/renders the body and which extension it materializes to. Defaults to `tsx`.
 *  - `body_style` — a **tsx-codec-local flavor** (`full | inline`), nullable/meaningless for other
 *    formats. Governs auto-import-preamble injection in the TSX codec.
 *
 * Runs in BOTH the central `migrate` and tenant `tenants:migrate` passes — same shared-dir mechanism
 * S0 established via {@see BeamUxServiceProvider::bootMigrations()}, so the columns exist identically
 * central + every tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            // Sibling axis to `type` (ADR-0164): the body language / codec + file extension.
            $table->string('format')->default(UxFormat::Tsx->value)->index()->after('type');

            // A tsx-codec-local flavor; nullable because it is meaningless for non-tsx formats.
            $table->string('body_style')->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('beam_ux_entries', function (Blueprint $table) {
            $table->dropColumn(['format', 'body_style']);
        });
    }
};
