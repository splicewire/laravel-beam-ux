<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Illuminate\Database\Connection;
use Mockery;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Doctor\RecordPageIsolationAudit;
use Splicewire\Beam\Ux\Tests\TestCase;

class RecordPageIsolationAuditTest extends TestCase
{
    public function test_the_native_manifest_registers_an_advisory(): void
    {
        $registration = app(\Splicewire\Beam\Doctor\BeamDoctorManifest::class)->unfiltered()
            ->resolve(\Rushing\Popcorn\Registries\ClassKey::of(RecordPageIsolationAudit::class));
        $this->assertFalse($registration->gate);
        $this->assertSame('splicewire/laravel-beam-ux', $registration->package);
    }

    public function test_mysql_repeatable_read_warns_and_read_committed_passes(): void
    {
        foreach (['REPEATABLE-READ' => DoctorStatus::Warn, 'READ-COMMITTED' => DoctorStatus::Pass] as $level => $status) {
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
            $connection->shouldReceive('selectOne')->once()->with('SELECT @@transaction_isolation AS isolation_level')->andReturn((object) ['isolation_level' => $level]);
            $finding = (new RecordPageIsolationAudit)->inspect($connection)[0];
            $this->assertSame($status, $finding->status);
            $this->assertTrue($finding->conclusive);
            if ($status === DoctorStatus::Warn) {
                $this->assertStringContainsString('concurrent', $finding->detail);
            }
        }
    }

    public function test_non_mysql_or_unreadable_isolation_is_not_falsely_measured_clean(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldNotReceive('selectOne');
        $this->assertFalse((new RecordPageIsolationAudit)->inspect($connection)[0]->conclusive);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mariadb');
        $connection->shouldReceive('selectOne')->once()->andThrow(new \RuntimeException('private connection error'));
        $finding = (new RecordPageIsolationAudit)->inspect($connection)[0];
        $this->assertFalse($finding->conclusive);
        $this->assertStringNotContainsString('private connection error', $finding->detail);
    }
}
