<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
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
        {--author= : The user id the conversion\'s Release is made by (not needed for a dry run)}
        {--dry-run : List what would change, and write nothing}';

    protected $description = 'Convert a host\'s MDX once: its files for one reviewed commit, its stored pages into one open Release (ADR-0233 d9).';

    public function handle(MdxFileConverter $files, StoredMdxConversion $stored): int
    {
        if (! config('beam.ux.mdx.holds_blocks')) {
            $this->error('Turn beam.ux.mdx.holds_blocks on first: the converted pages hold blocks (ADR-0233 d1, d9).');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $author = null;
        if (! $dry) {
            $author = $this->option('author') === null ? null : Auth::getProvider()->retrieveById($this->option('author'));
            if ($author === null) {
                $this->error('Name the author the Release is made by: --author=<user id>.');

                return self::FAILURE;
            }
        }
        $root = (string) ($this->option('path') ?: config('beam.mdx.content_path', resource_path('js/content')));

        $files = $files->convert($root, write: ! $dry);
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

        $report = $stored->convert($root, $author, write: ! $dry);

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
}
