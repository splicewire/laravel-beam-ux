<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
 * Runs in BOTH the central `migrate` and tenant `tenants:migrate` passes — shipped PUBLISH-ONLY via the
 * plain provider's {@see ServiceProvider::publishesMigrations()} (`beam-ux-migrations` tag), same as S0:
 * `vendor:publish` copies this flat file into the host's `database/migrations/` and its `tenant/` twin
 * into `database/migrations/tenant/`, and the HOST runs each pass, so the columns exist identically
 * central + every tenant. This alter's `170001` timestamp sorts it right after S0's `170000` create.
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
