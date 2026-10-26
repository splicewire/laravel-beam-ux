<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Splicewire\Beam\Mdx\Convert\MdxConversion;
use Splicewire\Beam\Mdx\Convert\MdxFileConverter;
use Splicewire\Beam\Ux\Convert\StoredMdxConversion;

/**
 * A host converts its MDX once, at adoption (ADR-0233 d9; EP-27 ruling 4): its files for one reviewed commit
 * (laravel-beam-mdx's converter), and its stored MDX pages into one open Release an author previews and publishes. It
 * commits nothing and publishes nothing. The host turns `beam.ux.mdx.holds_blocks` on first, since the converted versions
 * hold blocks.
 */
class ConvertMdxCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:convert-mdx
        {--path= : The MDX content root (default: beam.mdx.content_path)}
        {--author= : The user id the conversion\'s Release is made by (a dry run rehearses as that author)}
        {--realm=site : The realm whose stored MDX pages convert (a Release holds one realm\'s entries)}
        {--dry-run : Rehearse the conversion and report what it would do, then roll it all back}
        {--restamp : Re-stamp the disk pages a conversion left unstamped because their file had moved on (no Release, no author); --dry-run reports only}';

    protected $description = 'Convert a host\'s MDX once: its files for one reviewed commit, its stored pages into one open Release (ADR-0233 d9).';

    public function handle(MdxFileConverter $files, StoredMdxConversion $stored): int
    {
        if (! config('beam.ux.mdx.holds_blocks')) {
            $this->error('Turn beam.ux.mdx.holds_blocks on first: the converted pages hold blocks (ADR-0233 d1, d9).');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        if ($this->option('restamp')) {
            return $this->restamp($stored, $dry, (string) $this->option('realm'));
        }
        // A dry run rehearses the writes ({@see \Splicewire\Beam\Ux\Convert\Rehearsal}), and a Release records who made it.
        $author = $this->option('author') === null ? null : Auth::getProvider()->retrieveById($this->option('author'));
        if ($author === null) {
            $this->error('Name the author the Release is made by: --author=<user id>.');

            return self::FAILURE;
        }
        $root = (string) ($this->option('path') ?: config('beam.mdx.content_path', resource_path('js/content')));

        // The stored pages convert first, and a page's file is written only once its stored page has (EP-42, orch-lead
        // 2026-10-26): a page the stored write refuses keeps its file, rather than a reprint over a row holding source.
        $plan = $files->convert($root, write: false);
        $report = $stored->convert($root, $author, write: ! $dry, realm: (string) $this->option('realm'), planned: $plan->printed);
        $held = array_intersect_key($report['files'], array_flip($plan->converted));
        $files = $dry ? $this->heldBack($plan, $held) : $files->write($root, $plan, $held);

        $this->info(($dry ? 'Would convert ' : 'Converted ').count($files->converted).' MDX file(s) under '.$root.' for one reviewed commit; '
            .count($files->unchanged).' already canonical.');
        foreach ($files->converted as $file) {
            $this->line("  file {$file}");
        }
        foreach ($files->leftOut as $file => $why) {
            $this->warn("  left as it is: {$file}: {$why}");
        }
        foreach ($files->unusedFragments as $fragment) {
            $this->warn("  no page includes {$fragment}: reported, not converted");
        }

        $this->info(($dry ? 'Would convert ' : 'Converted ').count($report['pages']).' stored MDX page(s) and make '.count($report['components']).' component(s).');
        foreach ($report['pages'] as $slug) {
            $this->line("  page {$slug}");
        }
        foreach ($report['components'] as $component => $fragment) {
            $this->line("  <{$component} /> from {$fragment}");
        }
        foreach ($report['leftOut'] as $what => $why) {
            $this->warn("  left as it is: {$what}: {$why}");
        }
        if ($report['release'] !== null) {
            $this->info("Release {$report['release']} holds them, open: preview it, then publish it.");
        }

        return self::SUCCESS;
    }

    /**
     * A dry run's files report: the plan less the files the real run would hold back.
     *
     * @param  array<string, string>  $held  path => why
     */
    private function heldBack(MdxConversion $plan, array $held): MdxConversion
    {
        $report = clone $plan;
        $report->converted = array_values(array_diff($plan->converted, array_keys($held)));
        $report->leftOut = [...$plan->leftOut, ...$held];
        ksort($report->leftOut);

        return $report;
    }

    /** `--restamp`: the disk pages a conversion left unstamped, re-stamped so the next re-assert brings their file. */
    private function restamp(StoredMdxConversion $stored, bool $dry, string $realm): int
    {
        $report = $stored->restampConverted($realm, write: ! $dry);
        $this->info(($dry ? 'Would re-stamp ' : 'Re-stamped ').count($report['restamped']).' page(s) a conversion left unstamped.');
        foreach ($report['restamped'] as $slug) {
            $this->line("  page {$slug}");
        }
        foreach ($report['leftOut'] as $slug => $why) {
            $this->warn("  left as it is: {$slug}: {$why}");
        }

        return self::SUCCESS;
    }
}
