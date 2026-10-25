<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * ADR-0233's two doctor checks on a host's MDX pages.
 *
 * **The gate is on only where the host converted** (d1, d9). `beam.ux.mdx.holds_blocks` turns on MDX pages that hold
 * blocks, and a host turns it on once each stored MDX page holds blocks (EP-42's conversion). The check FAILS while the
 * gate is on and any MDX page's working copy or published version still holds source (a `{frontmatter, content}`
 * body), because the canvas, the mirror and the Files action would read that page as blocks it does not have. A gate
 * that is off passes: the host keeps MDX as source, as before. The files on disk are not checked; the stored pages are
 * what every reader serves, and the conversion command reports its files itself.
 *
 * **A retired command still scheduled** (EP-26 G1): where the gate is on, a scheduled `enrich-page-schemas` (which
 * refuses there) warns.
 *
 * **Unclaimed frontmatter keys** (d3). A key nothing declares is kept in the page's version (the page settings'
 * `unclaimed`) and printed back by the mirror; this reports which published pages keep any. It warns and never fails:
 * which keys a host's authors write is a fact about the host. A zero says how many pages it counted.
 */
class BeamUxMdxPagesAudit implements DoctorAudit
{
    /** How many slugs a finding names before it summarizes the rest. */
    private const NAMED = 10;

    /** @return list<Finding> */
    public function run(): array
    {
        if (! Schema::hasTable('beam_ux_entries')) {
            return [Finding::warn('MDX pages', 'beam_ux_entries is absent — publish + migrate beam-ux before auditing MDX pages.')];
        }

        $pages = BeamUxEntry::query()->where('type', UxType::Page->value)->get();

        return [$this->gate($pages), $this->unclaimed($pages), ...$this->retiredSchedule()];
    }

    /**
     * EP-26 G1: `splicewire:beam:ux:enrich-page-schemas` retires (d3) and refuses to run where MDX pages hold blocks,
     * so a host that still schedules it there fails every run. Warns, naming the command; nothing when it is not
     * scheduled or the gate is off.
     *
     * @return list<Finding>
     */
    private function retiredSchedule(): array
    {
        if (! config('beam.ux.mdx.holds_blocks') || ! app()->bound(\Illuminate\Console\Scheduling\Schedule::class)) {
            return [];
        }
        foreach (app(\Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'splicewire:beam:ux:enrich-page-schemas')) {
                return [Finding::warn('Retired MDX commands', 'splicewire:beam:ux:enrich-page-schemas is still scheduled, but MDX pages hold blocks here, '
                    .'so it refuses every run. Remove it from the schedule (ADR-0233 d3).')];
            }
        }

        return [];
    }

    /** @param  iterable<BeamUxEntry>  $pages */
    private function gate(iterable $pages): Finding
    {
        $check = 'MDX pages hold blocks (beam.ux.mdx.holds_blocks)';
        if (! config('beam.ux.mdx.holds_blocks')) {
            return Finding::pass($check, 'the gate is off, so MDX pages hold source as before.');
        }

        $mdx = 0;
        $source = [];
        foreach ($pages as $page) {
            if ($page->format !== UxFormat::Mdx) {
                continue;
            }
            $mdx++;
            // The working copy through the entry's own storage driver, which is what every editor reads.
            $working = $page->particle_id === null ? null
                : app(\Splicewire\Beam\Ux\Storage\StorageDriverResolver::class)->resolve($page)->read((string) $page->particle_id)?->body;
            $bodies = [$working, $this->published($page)['payload'] ?? null];
            foreach ($bodies as $body) {
                if (is_array($body) && $body !== [] && ! JsonDocShape::is($body)) {
                    $source[] = (string) $page->slug;

                    break;
                }
            }
        }

        if ($source !== []) {
            return Finding::fail($check, 'the gate is on, but '.count($source).' of '.$mdx.' MDX pages still hold source ('
                .$this->names($source).'). Convert them first (ADR-0233 d9) or turn the gate off.');
        }

        return Finding::pass($check, "the gate is on and all {$mdx} MDX pages hold blocks.");
    }

    /** @param  iterable<BeamUxEntry>  $pages */
    private function unclaimed(iterable $pages): Finding
    {
        $check = 'Unclaimed frontmatter keys';
        $published = 0;
        $kept = [];
        foreach ($pages as $page) {
            $snapshot = $this->published($page);
            if ($snapshot === null) {
                continue;
            }
            $published++;
            $keys = array_keys($snapshot['meta']['pageSettings']['unclaimed'] ?? []);
            if ($keys !== []) {
                $kept[] = $page->slug.' ('.implode(', ', $keys).')';
            }
        }

        if ($kept !== []) {
            return Finding::warn($check, count($kept).' of '.$published.' published pages keep frontmatter keys nothing declares: '
                .$this->names($kept).'. Declare them as Page Fields to give them a reader, or remove them in page settings.');
        }

        return Finding::pass($check, "{$published} published pages checked; none keep a frontmatter key nothing declares.");
    }

    /** @return array<string, mixed>|null the published version's snapshot */
    private function published(BeamUxEntry $page): ?array
    {
        $pin = $page->getAttribute('published_version');
        if ($page->particle_id === null || $pin === null || ! app()->bound(VersionStore::class)) {
            return null;
        }

        return app(VersionStore::class)->version($page->versionable(), (string) $pin)?->snapshot;
    }

    /** @param  list<string>  $names */
    private function names(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::NAMED));

        return count($names) > self::NAMED ? $shown.' and '.(count($names) - self::NAMED).' more' : $shown;
    }
}
