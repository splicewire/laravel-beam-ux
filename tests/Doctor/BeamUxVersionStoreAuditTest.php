<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Ux\Doctor\BeamUxVersionStoreAudit;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;
use Splicewire\Beam\Ux\Type\UxType;

/** ADR-0225 decision 2: the pin is required wherever the entry editor is mounted; this names the host. */
class BeamUxVersionStoreAuditTest extends TestCase
{
    private function mount(string $op): void
    {
        // Exactly what `Particle::ops('beam-ux-entries', 'beam-ux-entry', $op)` leaves on the route.
        Route::post("beam-ux-entries/{id}/{$op}", [ParticleOperationController::class, 'invoke'])
            ->defaults(ParticleOperationController::RESOURCE, 'beam-ux-entry')
            ->defaults(ParticleOperationController::NAME, $op);
    }

    private function tables(bool $column, bool $versions): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) use ($column) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable();
            $table->string('slug');
            $table->string('type');
            $table->string('format')->default('tsx');
            $table->string('namespace')->nullable();
            $table->string('residency_mode')->default('context-following');
            $table->string('realm')->default('site');
            $table->json('realms')->nullable();
            if ($column) {
                $table->uuid('published_version')->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
        if ($versions) {
            Schema::create(Beam::table('versions'), function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('versionable_type');
                $table->string('versionable_id');
                $table->unsignedInteger('version');
                $table->json('snapshot');
                $table->string('label')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    private function auditStatus(): DoctorStatus
    {
        return app(BeamUxVersionStoreAudit::class)->run()[0]->status;
    }

    private function auditDetail(): string
    {
        return app(BeamUxVersionStoreAudit::class)->run()[0]->detail;
    }

    public function test_a_host_that_mounts_no_entry_history_op_passes(): void
    {
        $this->tables(column: false, versions: false);

        $this->assertSame(DoctorStatus::Pass, $this->auditStatus());
    }

    public function test_mounted_ops_without_the_column_fail_and_name_the_fix(): void
    {
        $this->tables(column: false, versions: true);
        $this->mount('save-draft');

        $this->assertSame(DoctorStatus::Fail, $this->auditStatus());
        $this->assertStringContainsString('save-draft', $this->auditDetail());
        $this->assertStringContainsString('beam-ux-migrations', $this->auditDetail());
        $this->assertStringContainsString('published_version', $this->auditDetail());
    }

    public function test_mounted_ops_without_the_version_table_fail(): void
    {
        $this->tables(column: true, versions: false);
        $this->mount('publish');

        $this->assertSame(DoctorStatus::Fail, $this->auditStatus());
    }

    public function test_written_entries_without_a_pin_warn_and_point_at_the_baseline_migration(): void
    {
        $this->tables(column: true, versions: true);
        $this->mount('save-body');
        BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => (string) \Illuminate\Support\Str::uuid()]);

        $this->assertSame(DoctorStatus::Warn, $this->auditStatus());
        $this->assertStringContainsString('baseline_beam_ux_entry_publication_pins', $this->auditDetail());
    }

    public function test_mounted_ops_with_the_store_and_every_entry_pinned_pass(): void
    {
        $this->tables(column: true, versions: true);
        $this->mount('versions');
        BeamUxEntry::create(['slug' => 'pinned', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => (string) \Illuminate\Support\Str::uuid(), 'published_version' => (string) \Illuminate\Support\Str::uuid()]);

        $this->assertSame(DoctorStatus::Pass, $this->auditStatus());
    }
}
