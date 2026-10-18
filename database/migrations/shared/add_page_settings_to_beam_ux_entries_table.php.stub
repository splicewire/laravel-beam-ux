<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Published copies only; drafts live in the particle's versioned meta (ADR-0232 d1). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_entries')) {
            return;
        }
        foreach (['seo_description', 'seo_social_image', 'seo_page_type', 'seo_published_date'] as $column) {
            if (! Schema::hasColumn('beam_ux_entries', $column)) {
                Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->text($column)->nullable());
            }
        }
        foreach (['first_published_at', 'last_published_at'] as $column) {
            if (! Schema::hasColumn('beam_ux_entries', $column)) {
                Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->timestamp($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['seo_description', 'seo_social_image', 'seo_page_type', 'seo_published_date', 'first_published_at', 'last_published_at'] as $column) {
            if (Schema::hasColumn('beam_ux_entries', $column)) {
                Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
