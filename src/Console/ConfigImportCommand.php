<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Ux\Recording\ConfigOverrideMirror;
use Throwable;

/**
 * `splicewire:beam:config:import` — read a `config/overrides/<root>.json` mirror (config-sections ticket 08) back into
 * the section's DRAFT. The file a Release recording wrote on one worktree becomes a pending draft on another, which an
 * operator reviews and publishes through the normal path.
 *
 * It NEVER publishes. Import only calls {@see ConfigOverrideStore::saveDraft()}; promoting the draft stays a reviewed,
 * authorized action (a Release, or the publish op), never a side effect of reading a file. Secrets are not in the file,
 * so a secret pointer leaves the existing secret override untouched ({@see ConfigOverrideMirror::toOps()}). The command
 * prints counts only — never an override value.
 */
class ConfigImportCommand extends Command
{
    protected $signature = 'splicewire:beam:config:import
        {root : the config section root, e.g. beam.ux}
        {file : path to the config/overrides/<root>.json mirror}
        {--dry-run : parse and report, write no draft}';

    protected $description = 'Import a config override mirror file into the section draft (never publishes).';

    public function handle(Filesystem $files, ConfigOverrideStore $store, ConfigSectionRegistry $sections): int
    {
        $root = (string) $this->argument('root');
        $path = (string) $this->argument('file');

        if ($sections->get($root) === null) {
            $this->error("No config section is registered for root '{$root}'.");

            return self::FAILURE;
        }
        if (! $files->exists($path)) {
            $this->error("Mirror file not found: {$path}");

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $document */
            $document = json_decode($files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error("Mirror file is not valid JSON: {$e->getMessage()}");

            return self::FAILURE;
        }
        if (! is_array($document) || ($document['root'] ?? null) !== $root) {
            $this->error("Mirror file's root (".json_encode($document['root'] ?? null).") does not match '{$root}'.");

            return self::FAILURE;
        }

        // Validate the mirror grammar (CONTRACT-11 rev 4 section 2b) BEFORE anything else, so a malformed file fails with
        // a named fault rather than being silently filtered to a no-op success (config-sections 11 r6). Shape only: a
        // plain {pointer, op, value} row is valid, and so is one whose pointer is now secret (import skips it below).
        if (($fault = ConfigOverrideMirror::grammarFault($document)) !== null) {
            $this->error("Mirror file does not match the section-2b grammar: {$fault}.");

            return self::FAILURE;
        }

        // Pass the current section so toOps leaves a now-secret key's stale plaintext in an OLD file untouched, exactly
        // as it leaves a marker row (config-sections 11 r5): the declaration decides, not only the stored flag.
        $section = $sections->get($root)?->section;
        $ops = ConfigOverrideMirror::toOps($document, $section);
        $secrets = count(array_filter($document['overrides'] ?? [], fn ($o): bool => is_array($o) && ($o['secret'] ?? false) === true));
        $this->info(sprintf('Parsed %d override op(s) for %s; %d secret pointer(s) left untouched (not in the file).', count($ops), $root, $secrets));

        if ($this->option('dry-run')) {
            $this->line('Dry run: no draft written.');

            return self::SUCCESS;
        }

        // A VALID mirror whose rows reconstruct no op is a no-op, not a failure: every row is a secret marker or a
        // now-secret pointer left untouched, or a parent whose only children are secret (its value is the empty object).
        // saveDraft refuses an empty op list, but there is simply nothing to draft here. (Malformed input already failed.)
        if ($ops === []) {
            $this->info("Nothing to import for {$root}: the mirror reconstructs no draft op (its rows leave existing secrets untouched). No draft written.");

            return self::SUCCESS;
        }

        try {
            $store->saveDraft($root, $ops);
        } catch (Throwable $e) {
            $this->error("Draft refused: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Draft saved for {$root}. Review and publish it through the normal path; import never publishes.");

        return self::SUCCESS;
    }
}
