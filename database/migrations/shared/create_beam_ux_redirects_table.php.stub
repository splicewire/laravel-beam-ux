<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Redirect is a row of its own (ADR-0236 d1): one site's old path, exactly or with everything under it, to a page held
 * by identity, a Record, a path on the site or an address on another site; permanent or temporary; with the origin that
 * left it. It lives with the site's entries, so a Hosted site's are in its tenant schema. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_redirects')) {
            return;
        }
        Schema::create('beam_ux_redirects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('realm')->default('site');
            $table->string('path', 512);
            $table->string('match', 16)->default('exact');
            $table->string('target_kind', 16);
            $table->uuid('target_entry_id')->nullable()->index();
            $table->string('target_collection')->nullable();
            $table->string('target_record_id')->nullable();
            $table->string('target_path', 512)->nullable();
            $table->string('target_url', 2048)->nullable();
            $table->boolean('permanent')->default(true);
            $table->string('origin', 16)->default('author');
            $table->string('origin_ref')->nullable();
            $table->string('created_by')->nullable();
            $table->string('edited_by')->nullable();
            $table->timestamps();
            $table->unique(['realm', 'path', 'match']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beam_ux_redirects');
    }
};
