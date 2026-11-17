<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Nav\NavSource;

/**
 * `php artisan splicewire:beam:ux:seed-nav {--namespace=}` — the DATA-DRIVEN content-nav seeder (F02).
 *
 * Seeds the per-realm CONTENT nav — the entry rows (`segment`/`realm`/`nav_order`) the
 * NavProjector reads. It reads its data from {@see NavSource} (config `beam.ux.nav` → disk
 * `resources/beam-ux/nav.{yml,json}` → DERIVED from entry frontmatter), so the app provides only DATA,
 * never a bespoke command. A fresh site whose page files carry `segment`/`realm`/`nav_order` frontmatter
 * seeds its nav with NO bespoke PHP — the derivation carries it.
 *
 * New rows are born published through the column-tolerant publishedMarkingAttributes helper.
 * Re-running restamps realm/segment/title/order without duplicating or changing an existing marking.
 * Legacy null markings remain uninitialized/hidden on a workflow-managed host: an authorized operator
 * reviews and publishes those rows through the normal workflow API, rather than seed-nav guessing
 * whether an existing page was intended to stay a draft.
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `seed-nav`.
 */
class SeedNavCommand extends Command
{
    /**
     * A DERIVED nav source resolved no rows: a benign skip, NOT a failure (launch 76). It is legitimate whenever
     * seeding runs before any entry carries nav data, e.g. a central seed where only the realm root exists and the
     * docs register later. Distinct from SUCCESS so a caller ({@see \Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder})
     * can report the nav as skipped rather than seeded; distinct from FAILURE so it is not counted as one. A DECLARED
     * source (config or disk) that resolves no rows is still FAILURE: that is an authoring error, not "not yet".
     */
    public const NO_DATA = 3;

    protected $signature = 'splicewire:beam:ux:seed-nav {--namespace= : Disk-grouping namespace (default config beam.ux.namespace)}';

    protected $description = 'Seed the content nav (per-realm sitemaps) from config/disk/derived data — no hard-coded array.';

    public function handle(NavSource $source): int
    {
        $namespace = $this->namespace();
        $rows = $source->resolve($namespace);

        if ($rows === [] && $source->isDerived($namespace)) {
            // Derived and empty: no entry carries nav data YET (a central seed before the docs register). Benign.
            $this->components->info(
                "No nav data for namespace [{$namespace}]: no entry carries segment/realm/nav_order frontmatter yet, so nothing seeded."
            );

            return self::NO_DATA;
        }

        if ($rows === []) {
            // A DECLARED source (config('beam.ux.nav') or a nav.{yml,json}) that resolves no rows is an authoring error.
            $this->components->error(
                "No nav data for namespace [{$namespace}] — set config('beam.ux.nav'), author resources/beam-ux/nav.{yml,json}, or register entries with segment/realm/nav_order frontmatter first."
            );

            return self::FAILURE;
        }

        $provisionedRoots = [];
        $order = 0;

        foreach ($rows as $row) {
            $order += 10; // gaps of 10 so a later insert can slot between without a full re-stamp.
            $realm = $row['realm'];

            // Auto-provision the realm's canonical root entry once per realm seen (ticket 03).
            if (! isset($provisionedRoots[$realm])) {
                BeamUxEntry::rootFor($realm);
                $provisionedRoots[$realm] = true;
            }

            $attributes = ['slug' => $row['slug'], 'namespace' => $namespace];
            $values = [
                'type' => $row['type'],
                'realm' => $realm,
                'realms' => [$realm],
                'segment' => $row['segment'],
                'title' => $row['title'],
                // Preserve an explicit authored order; else the derived display sequence.
                'nav_order' => $row['nav_order'] ?? $order,
            ];
            if (isset($row['entry_id'])) {
                // A derived row already exists: namespace, containment and realm fallbacks remain authored.
                $entry = BeamUxEntry::findOrFail($row['entry_id']);
                $entry->fill(['nav_order' => $values['nav_order']])->save();
            } else {
                // Ungrouped seeded/disk entries use NULL; older command-authored entries can use ''.
                // Reuse either existing identity without migrating it or creating a second page.
                $existing = $namespace === ''
                    ? (BeamUxEntry::query()->where($attributes)->first()
                        ?? BeamUxEntry::query()->where('slug', $row['slug'])->whereNull('namespace')->first())
                    : null;
                $entry = $existing ?? BeamUxEntry::firstOrCreate($attributes, array_merge($values, BeamUxEntry::publishedMarkingAttributes()));
                // Re-seeding navigation must not publish an author's existing draft or archived page.
                if (! $entry->wasRecentlyCreated) {
                    $entry->fill($values)->save();
                }
            }

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
}
