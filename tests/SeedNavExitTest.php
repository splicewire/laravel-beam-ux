<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Console\SeedNavCommand;
use Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder;

class SeedNavExitTest extends TestCase
{
    // A DECLARED nav (config) that resolves no rows is an authoring error and still fails the run (launch 76 keeps this;
    // only a DERIVED empty nav is the benign skip; see AuthoringCommandsTest for that, which needs the entries table).
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

    public function test_a_nested_no_data_answer_returns_normally_and_reports_the_skip(): void
    {
        $this->assertNoDataCodeExists();
        config(['beam.ux.seed_nav' => true]);
        $output = $this->createMock(OutputStyle::class);
        $output->expects($this->once())->method('writeln')->with($this->stringContains('no nav data — nav skipped'));
        $command = $this->createMock(Command::class);
        $command->expects($this->once())->method('call')->with(SeedNavCommand::class)->willReturn(SeedNavCommand::NO_DATA);
        $command->method('getOutput')->willReturn($output);

        (new NavOnlySeeder)->setCommand($command)->run();
    }

    public function test_a_facade_no_data_answer_returns_normally(): void
    {
        $this->assertNoDataCodeExists();
        config(['beam.ux.seed_nav' => true]);
        $kernel = $this->createMock(Kernel::class);
        $kernel->expects($this->once())->method('call')->with(SeedNavCommand::class)->willReturn(SeedNavCommand::NO_DATA);
        Artisan::swap($kernel);
        (new NavOnlySeeder)->run();
        $this->addToAssertionCount(1);
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

    /** launch 76's NO_DATA code; asserted first so a build without it fails on an assertion, not an Error. */
    private function assertNoDataCodeExists(): void
    {
        $this->assertTrue(defined(SeedNavCommand::class.'::NO_DATA'), 'SeedNavCommand::NO_DATA (launch 76)');
    }
}

class NavOnlySeeder extends BeamUxSeeder
{
    public function run(): void
    {
        $this->seedNav();
    }
}
