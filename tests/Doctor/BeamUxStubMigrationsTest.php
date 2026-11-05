<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * beam-ux's own operator check: its migrations must stay publish-only .stub files. Mirrors beam-core's
 * `BeamCoreStubMigrationsTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology` pattern)
 * — a thin test wrapping a shared engine, declaring only "which package is mine."
 */
class BeamUxStubMigrationsTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_ux_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsPackage(): ?string
    {
        return 'splicewire/laravel-beam-ux';
    }

    protected function stubMigrationsProvider(): ?string
    {
        return BeamUxServiceProvider::class;
    }
}
