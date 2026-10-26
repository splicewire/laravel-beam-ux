<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_preview_open_nonces')) {
            return;
        }

        Schema::create('beam_ux_preview_open_nonces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('nonce_hash', 64)->unique();
            $table->uuid('release_id')->index();
            $table->char('actor_token_hash', 64);
            $table->string('tenant_id')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beam_ux_preview_open_nonces');
    }
};
