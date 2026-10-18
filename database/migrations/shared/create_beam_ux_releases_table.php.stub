<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_releases')) {
            Schema::create('beam_ux_releases', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('title');
                $table->string('realm');
                $table->string('created_by');
                $table->string('edited_by');
                $table->string('reviewed_by')->nullable();
                $table->unsignedInteger('revision')->default(0);
                $table->string('schedule_token')->nullable();
                $table->string('status')->default('draft');
                $table->uuid('workflow_version')->nullable();
                $table->uuid('rollback_of')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('beam_ux_release_members')) {
            Schema::create('beam_ux_release_members', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('release_id')->index();
                $table->uuid('entry_id')->index();
                $table->uuid('version_id')->nullable();
                $table->uuid('observed_pin')->nullable();
                $table->string('observed_marking')->nullable();
                $table->string('redirect_target')->nullable();
                $table->timestamps();
                $table->unique(['release_id', 'entry_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('beam_ux_release_members');
        Schema::dropIfExists('beam_ux_releases');
    }
};
