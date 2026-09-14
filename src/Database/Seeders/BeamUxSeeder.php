<?php

namespace Splicewire\Beam\Ux\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Console\SeedNavCommand;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Seed\SeedsEntries;

/**
 * beam-ux's single {@see BeamSeedManifest} step, provisioning the realm root and then its content navigation. Documentation is contributed by the optional docs package.
 *
 * **Why one seeder and not three.** `BeamSeedManifest::register()` is idempotent *per package name* —
 * re-registering REPLACES — so one package gets exactly one step. A package with more than one thing to
 * seed composes them here rather than registering twice, where the second registration would silently
 * delete the first. This is worth knowing before writing a contributor's seeder (ADR-0210 §1).
 *
 * Each part carries its own gate so the composition does not cost the granularity the separate
 * registrations would have had.
 */
class BeamUxSeeder extends Seeder
{
    use SeedsEntries;

    public function run(): void
    {
        $this->seedRealmRoot();
        $this->seedNav();
    }

    /**
     * The realm root (ADR-0209 §9). `BeamUxEntry::rootFor()` is a `firstOrCreate`, and the renderer
     * deliberately never calls it: a GET that silently INSERTs breaks on read-replica topologies and
     * races under concurrent first-hits. So the root is provisioned HERE, explicitly, and its absence at
     * request time means "nothing to serve" rather than "create one".
     *
     * `rootFor()` was found to have no production callers at all — only test fixtures — which is why no
     * live database had a root row and why the containment tree had a top nothing hung from.
     */
    protected function seedRealmRoot(): void
    {
        if (! $this->canSeed()) {
            $this->report('beam-ux: beam_ux_entries is absent — skipping entry seeding.');

            return;
        }

        BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE);
    }

    /**
     * The content nav, over the realm root and any contributor entries.
     * Gated by `beam.ux.seed_nav` — a gate that used to live on the manifest registration itself and
     * moved inline when the three steps folded into one seeder.
     */
    protected function seedNav(): void
    {
        if (! config('beam.ux.seed_nav', true)) {
            return;
        }

        if ($this->command !== null) {
            $this->command->call(SeedNavCommand::class);

            return;
        }

        Artisan::call(SeedNavCommand::class);
    }

    private function report(string $message): void
    {
        $this->command?->getOutput()->writeln("  <comment>{$message}</comment>");
    }
}
