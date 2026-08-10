<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Database\Seeders\NavSeeder;

/**
 * beam-ux self-registers its NavSeeder — a thin `db:seed` adapter over `splicewire:beam:ux:seed-nav` — into
 * beam-core's package-registered seed manifest (`splicewire:beam:seed`), gated by `beam.ux.seed_nav` so one
 * aggregate run restamps the content nav alongside every other package after a migrate:fresh.
 */
class SeedManifestTest extends TestCase
{
    public function test_it_registers_the_nav_seeder_into_the_beam_seed_manifest_gated(): void
    {
        $step = collect($this->app->make(BeamSeedManifest::class)->steps())
            ->first(fn ($s) => $s->seeder === NavSeeder::class);

        $this->assertNotNull($step);
        $this->assertSame('splicewire/laravel-beam-ux', $step->package);
        $this->assertSame('beam.ux.seed_nav', $step->configGate);
    }

    public function test_the_seed_nav_gate_defaults_on(): void
    {
        $this->assertTrue((bool) config('beam.ux.seed_nav'));
    }
}
