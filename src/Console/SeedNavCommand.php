<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\Sitemap;
use Splicewire\Beam\Ux\Nav\NavSource;

/**
 * `php artisan splicewire:beam:ux:seed-nav {--namespace=}` — the DATA-DRIVEN content-nav seeder (F02).
 *
 * Seeds the per-realm sitemaps' CONTENT nav — the entry rows (`segment`/`realm`/`nav_order`) the
 * NavProjector reads. It reads its data from {@see NavSource} (config `beam.ux.nav` → disk
 * `resources/beam-ux/nav.{yml,json}` → DERIVED from entry frontmatter), so the app provides only DATA,
 * never a bespoke command. A fresh site whose page files carry `segment`/`realm`/`nav_order` frontmatter
 * seeds its nav with NO bespoke PHP — the derivation carries it.
 *
 * Idempotent (`updateOrCreate` keyed by slug + namespace): re-running restamps realm/sitemap/segment/
 * title/order without duplicating. `composable` defaults per the `beam-realms.behavior` config list
 * (behavior realms get a fixed-template body → composable=false); a realm not named there is a content
 * realm (composable=true) — the entry model's own default.
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `seed-nav`.
 */
class SeedNavCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:seed-nav {--namespace= : Disk-grouping namespace (default config beam.ux.namespace)}';

    protected $description = 'Seed the content nav (per-realm sitemaps) from config/disk/derived data — no hard-coded array.';

    public function handle(NavSource $source): int
    {
        $namespace = $this->namespace();
        $rows = $source->resolve($namespace);

        if ($rows === []) {
            $this->components->warn(
                "No nav data for namespace [{$namespace}] — set config('beam.ux.nav'), author resources/beam-ux/nav.{yml,json}, or register entries with segment/realm/nav_order frontmatter first."
            );

            return self::SUCCESS;
        }

        $behaviorRealms = $this->behaviorRealms();
        $sitemaps = [];
        $order = 0;

        foreach ($rows as $row) {
            $order += 10; // gaps of 10 so a later insert can slot between without a full re-stamp.
            $realm = $row['realm'];
            $sitemap = $sitemaps[$realm] ??= Sitemap::forRealm($realm);

            BeamUxEntry::updateOrCreate(
                ['slug' => $row['slug'], 'namespace' => $namespace],
                [
                    'type' => $row['type'],
                    'realm' => $realm,
                    // Behavior realms (auth/studio/checkout/operator) carry a fixed-template body →
                    // composable=false; every other realm is a content realm (composable). Config-driven
                    // via `beam-realms.behavior` so a host adds a behavior realm without editing here.
                    'composable' => ! in_array($realm, $behaviorRealms, true),
                    'sitemap_id' => $sitemap->getKey(),
                    'parent_id' => null,
                    'segment' => $row['segment'],
                    'title' => $row['title'],
                    // Preserve an explicit authored order; else the derived display sequence.
                    'nav_order' => $row['nav_order'] ?? $order,
                ],
            );

            $title = $row['title'] ?? $row['slug'];
            $this->line("  <info>✓</info> {$title}  <comment>{$row['segment']}</comment>  ({$row['slug']}) <fg=gray>[{$realm}]</>");
        }

        $via = $source->isDerived($namespace) ? 'derived from entry frontmatter' : 'from config/disk';
        $this->components->info(sprintf('Seeded %d nav entr(ies) under [%s] (%s).', count($rows), $namespace, $via));

        return self::SUCCESS;
    }

    /** The disk-grouping namespace — the `--namespace` option, else `config('beam.ux.namespace')`. */
    private function namespace(): string
    {
        $option = $this->option('namespace');

        return is_string($option) && $option !== '' ? $option : (string) config('beam.ux.namespace', '');
    }

    /**
     * The behavior-realm list (fixed-template body, composable=false), config-overridable via
     * `beam-realms.behavior` so a host names its own without editing the package.
     *
     * @return list<string>
     */
    private function behaviorRealms(): array
    {
        $configured = config('beam-realms.behavior');

        return is_array($configured)
            ? array_values(array_map('strval', $configured))
            : [];
    }
}
