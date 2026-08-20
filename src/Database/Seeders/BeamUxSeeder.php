<?php

namespace Splicewire\Beam\Ux\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Console\SeedNavCommand;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Seed\SeedsEntries;
use Splicewire\Beam\Ux\Seed\StubContent;

/**
 * beam-ux's single {@see BeamSeedManifest} step, running the three things a fresh host needs seeded in
 * dependency order: the realm root, the docs subtree beneath it, then the content nav over both.
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
        $this->seedDocs();
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
     * The docs subtree: a docs root plus the API reference page beam-ux contributes (ADR-0210).
     *
     * **The API reference page is seeded by beam-ux, not by beam core.** Core requires no splicewire
     * package and has no knowledge of `BeamUxEntry`, so it must not grow a conditional dependency on one
     * to seed a page about itself — even though the spec the page renders is core's route
     * (`beam.openapi.yaml`, ticket 21). The route name is interpolated into the stub because a seed body
     * cannot call `route()`, and hardcoding the path in the MDX would be a second place it is written down.
     *
     * The docs root's `segment` comes from config as its INITIAL value only. Re-rooting to `/beam/docs`
     * afterwards is an edit to this one row — the whole reason ticket 02 ruled docs a containment subtree
     * rather than a realm or a config key.
     */
    protected function seedDocs(): void
    {
        if (! $this->canSeed() || ! config('beam.ux.docs.seed', true)) {
            return;
        }

        $index = StubContent::read('docs/index.mdx');

        if ($index === null) {
            return;
        }

        $root = $this->seedPage('docs', $index->body, array_merge($index->columns(), [
            'segment' => (string) config('beam.ux.docs.segment', '/docs'),
            'parent_id' => BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE)->getKey(),
        ]));

        if ($root === null) {
            return;
        }

        $api = StubContent::read('docs/api.mdx', ['openapi_url' => $this->openApiUrl()]);

        if ($api === null) {
            return;
        }

        $this->seedPage('docs-api', $api->body, array_merge($api->columns(), [
            'parent_id' => $root->getKey(),
        ]));
    }

    /**
     * The content nav, over whatever the two steps above (and any contributor's) have registered.
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

    /**
     * The spec URL the API reference page points at — beam core's own artifact route (ticket 21,
     * ADR-0211). Falls back to the fixed package-owned path when the route is not registered, which is
     * the honest degrade: both URLs are fixed and package-owned precisely so a docs page can name one.
     */
    protected function openApiUrl(): string
    {
        return app('router')->has('beam.openapi.yaml')
            ? route('beam.openapi.yaml', absolute: false)
            : '/beam/openapi.yaml';
    }

    private function report(string $message): void
    {
        $this->command?->getOutput()->writeln("  <comment>{$message}</comment>");
    }
}
