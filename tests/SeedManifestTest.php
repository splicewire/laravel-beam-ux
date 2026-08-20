<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder;

/**
 * beam-ux self-registers ONE seeder into beam-core's package-registered seed manifest
 * (`splicewire:beam:seed`), so an aggregate run provisions the realm root (ADR-0209 §9), the docs subtree
 * (ADR-0210) and the content nav after a migrate:fresh.
 *
 * The registration is deliberately UNGATED and the gates moved inside the seeder:
 * `BeamSeedManifest::register()` is idempotent per PACKAGE name, so a second registration would silently
 * replace the first — one package gets one step and composes within it.
 */
class SeedManifestTest extends TestCase
{
    public function test_it_registers_one_ungated_seeder_into_the_beam_seed_manifest(): void
    {
        $steps = collect($this->app->make(BeamSeedManifest::class)->steps())
            ->filter(fn ($s) => $s->package === 'splicewire/laravel-beam-ux');

        $this->assertCount(1, $steps, 'one step per package — a second register() would replace the first.');

        $step = $steps->first();
        $this->assertSame(BeamUxSeeder::class, $step->seeder);
        $this->assertNull($step->configGate);
    }

    public function test_the_seed_nav_gate_defaults_on(): void
    {
        $this->assertTrue((bool) config('beam.ux.seed_nav'));
    }
}
