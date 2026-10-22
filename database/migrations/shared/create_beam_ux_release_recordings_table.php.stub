<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('beam_ux_release_recordings')) {
            return;
        }
        Schema::create('beam_ux_release_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('release_id')->unique();
            $table->string('state')->default('pending');
            $table->json('manifest');
            $table->text('repository')->nullable();
            $table->text('branch')->nullable();
            $table->text('preparation')->nullable();
            $table->string('base')->nullable();
            $table->string('tree')->nullable();
            $table->string('candidate')->nullable();
            $table->boolean('installation_started')->default(false);
            $table->string('sha')->nullable();
            $table->boolean('no_change')->default(false);
            $table->unsignedInteger('attempt')->default(0);
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beam_ux_release_recordings');
    }
};
