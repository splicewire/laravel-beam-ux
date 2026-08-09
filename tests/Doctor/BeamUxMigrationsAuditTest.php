<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Ux\Doctor\BeamUxMigrationsAudit;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * beam-ux's own operator check: its migrations must stay publish-only .stub files. Mirrors beam-core's
 * `BeamCoreMigrationsAuditTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology` pattern)
 * — a thin test wrapping a shared engine, declaring only "which audit is mine."
 */
class BeamUxMigrationsAuditTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_ux_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsAuditClass(): string
    {
        return BeamUxMigrationsAudit::class;
    }
}
