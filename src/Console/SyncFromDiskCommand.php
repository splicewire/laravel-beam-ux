<?php

namespace Splicewire\Beam\Ux\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Ux\Disk\PuckBridge;
use Splicewire\Beam\Ux\Disk\SyncPagesFromDisk;

/**
 * `php artisan splicewire:beam:ux:sync-from-disk {path}` — the operator-run **reverse Puck-page sync**
 * (beam Model-B ticket 08). Scans `{path}` for composed page `.tsx` files and, per the last-writer
 * policy, pulls any file NEWER than its record back into the DB particle body — parsing the JSX into
 * Puck `Data` via the Node {@see PuckBridge} (lossless JSX↔PuckData needs the
 * JS toolchain, so the parse runs in Node, not PHP).
 *
 * This is the inbound complement to `PuckPageCodegen`'s outbound DB→disk projection, and the correct
 * handling a plain `update-from-newer` cannot give a page (which would wrap the composed source as raw
 * `['source' => …]`). **Degrade-not-fabricate:** when the Node bridge is absent the command reports it
 * and does nothing — a page is never wrapped as raw source.
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `sync-from-disk`.
 */
class SyncFromDiskCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:sync-from-disk {path : The directory whose page .tsx files are synced back into the DB}';

    protected $description = 'Sync composed page .tsx edits on disk back into the DB particle body (Puck Data) via the Node bridge; last-writer by mtime.';

    public function handle(SyncPagesFromDisk $batch): int
    {
        if (! $batch->available()) {
            $this->components->warn(
                'The Node puck bridge is unavailable (beam.ux.puck.bridge.script) — no page .tsx edits sync back.',
            );

            return self::SUCCESS;
        }

        $path = (string) $this->argument('path');

        if (! is_dir($path)) {
            $this->components->error("Not a directory: {$path}");

            return self::FAILURE;
        }

        [$sources, $mtimes] = $this->readDisk($path);

        $result = $batch->run($sources, $mtimes);

        foreach ($result['updated'] as $entry) {
            $this->components->info("Synced [{$entry->namespace}].{$entry->slug} ← disk .tsx");
        }

        $this->components->info(sprintf(
            '%d page(s) synced from disk · %d unchanged.',
            count($result['updated']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }

    /**
     * Read every file under `$root`, keyed by its disk-relative path (the same key an entry's placement
     * resolves to), returning [source-map, mtime-map].
     *
     * @return array{0: array<string, string>, 1: array<string, int>}
     */
    protected function readDisk(string $root): array
    {
        $root = rtrim($root, '/');
        $sources = [];
        $mtimes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
            $sources[$relative] = (string) file_get_contents($file->getPathname());
            $mtimes[$relative] = (int) $file->getMTime();
        }

        return [$sources, $mtimes];
    }
}
