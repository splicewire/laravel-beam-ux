<?php

namespace Splicewire\Beam\Ux\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Ux\Console\SeedNavCommand;

/**
 * A thin `db:seed`-runnable adapter over {@see SeedNavCommand} (`splicewire:beam:ux:seed-nav`), so beam-ux's
 * content-nav seeding can join the package-registered `Splicewire\Beam\Seed\BeamSeedManifest` — which
 * runs each step as `db:seed --class`, a shape the command itself is not.
 *
 * The command owns all the logic (it reads its data from NavSource → config/disk/derived, idempotent
 * `updateOrCreate`); this wrapper only invokes it so `splicewire:beam:seed` restamps the per-realm content
 * nav alongside every other package's seeders after a `migrate:fresh`. Registered gated by `beam.ux.seed_nav`.
 */
class NavSeeder extends Seeder
{
    public function run(): void
    {
        // Prefer the running seeder's command context (so output threads through the parent run);
        // fall back to Artisan when invoked bare (no command context).
        if ($this->command !== null) {
            $this->command->call(SeedNavCommand::class);

            return;
        }

        Artisan::call(SeedNavCommand::class);
    }
}
