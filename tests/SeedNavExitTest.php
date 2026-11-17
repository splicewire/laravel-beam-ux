<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Console\SeedNavCommand;
use Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder;

class SeedNavExitTest extends TestCase
{
    public function test_an_empty_nav_reaches_the_manifest_failure_count(): void
    {
        config(['beam.ux.seed_nav' => true, 'beam.ux.nav' => []]);
        $manifest = new BeamSeedManifest;
        $manifest->register('splicewire/laravel-beam-ux', NavOnlySeeder::class);
        $this->app->instance(BeamSeedManifest::class, $manifest);

        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('0 seeded, 0 skipped (gate off), 1 FAILED')
            ->assertFailed();
    }

    public function test_a_failed_nested_nav_command_throws_instead_of_reporting_a_seeded_package(): void
    {
        config(['beam.ux.seed_nav' => true]);
        $command = $this->createMock(Command::class);
        $command->expects($this->once())->method('call')->with(SeedNavCommand::class)->willReturn(1);
        $seeder = (new NavOnlySeeder)->setCommand($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('seed-nav exited 1');
        $seeder->run();
    }

    public function test_a_failed_facade_nav_command_throws_too(): void
    {
        config(['beam.ux.seed_nav' => true]);
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->once())->method('call')->with(SeedNavCommand::class)->willReturn(2);
        Artisan::swap($kernel);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('seed-nav exited 2');
        (new NavOnlySeeder)->run();
    }

    public function test_a_successful_nav_command_returns_normally(): void
    {
        config(['beam.ux.seed_nav' => true]);
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->once())->method('call')->with(SeedNavCommand::class)->willReturn(0);
        Artisan::swap($kernel);
        (new NavOnlySeeder)->run();
        $this->addToAssertionCount(1);
    }

    public function test_disabling_nav_seeding_does_not_invoke_the_command(): void
    {
        config(['beam.ux.seed_nav' => false]);
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->never())->method('call');
        Artisan::swap($kernel);
        (new NavOnlySeeder)->run();
        $this->addToAssertionCount(1);
    }
}

class NavOnlySeeder extends BeamUxSeeder
{
    public function run(): void
    {
        $this->seedNav();
    }
}
