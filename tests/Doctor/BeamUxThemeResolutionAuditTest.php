<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Ux\Doctor\BeamUxThemeResolutionAudit;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * Ticket 07 (theme-entries-and-authoring): the doctor half of "a swallowed Throwable is REPORTED".
 * The audit runs the resolver ON READ and reads back what it swallowed — never a stamp taken at
 * register(), and never a Fail: which theme resolves is a fact about the host (AGENTS.md, "a check
 * whose answer depends on the host must not throw").
 */
class BeamUxThemeResolutionAuditTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    private function createHealthyTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('type')->index();
            $table->string('namespace')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function test_the_audit_is_registered_on_the_doctor_manifest_as_advisory(): void
    {
        // Testbench does not auto-discover: prove the manifest is the SHARED one, then that we are on it.
        $this->assertSame(app(BeamDoctorManifest::class), app(BeamDoctorManifest::class));

        $ours = array_values(array_filter(
            app(BeamDoctorManifest::class)->registrations(),
            fn (DoctorRegistration $r) => $r->audit === BeamUxThemeResolutionAudit::class,
        ));

        $this->assertCount(1, $ours, 'BeamUxThemeResolutionAudit is not registered on BeamDoctorManifest');
        $this->assertFalse($ours[0]->gate, 'the theme-resolution audit must be advisory, never a gate');
    }

    public function test_a_swallowed_non_absence_failure_warns_naming_the_entry_and_the_exception_class(): void
    {
        Exceptions::fake();
        $this->createHealthyTables('testing');
        // Central exists but is the wrong shape — a stale snapshot, not absence.
        Schema::connection('central')->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
        });

        $findings = app(BeamUxThemeResolutionAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('central:default', $findings[0]->detail);
        $this->assertStringContainsString('Illuminate\Database\QueryException', $findings[0]->detail);
        $this->assertStringContainsString('package defaults', $findings[0]->detail);
    }

    public function test_a_healthy_host_passes(): void
    {
        $this->createHealthyTables('testing');
        $this->createHealthyTables('central');

        $findings = app(BeamUxThemeResolutionAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
    }

    public function test_an_unmigrated_host_is_inconclusive_not_a_warning(): void
    {
        Exceptions::fake();

        $findings = app(BeamUxThemeResolutionAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        Exceptions::assertNothingReported();
    }
}
