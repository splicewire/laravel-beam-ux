<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_preview_grants')) {
            return;
        }

        Schema::create('beam_ux_preview_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->uuid('entry_id')->index();
            $table->uuid('version_id')->index();
            $table->uuid('release_id')->nullable()->index();
            $table->string('created_by');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beam_ux_preview_grants');
    }
};
