<?php

namespace Splicewire\Beam\Ux\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Ux\Disk\UpdateFromNewer;

/**
 * `php artisan splicewire:beam:ux:update-from-newer {path}` — the operator-run **`update-from-newer`**
 * batch (charter S8). Config-gated and **OFF by default** (`beam.ux.update_from_newer.enabled`): disk
 * edits flow back to the source-of-record ONLY on this explicit command with the gate on. Compares each
 * registered entry's file mtime against the record and, per the configured `direction`
 * (`disk-to-record` default | `record-to-disk`), moves the newer side's body. NO ambient watcher.
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `update-from-newer`.
 */
class UpdateFromNewerCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:update-from-newer {path : The directory whose files are reconciled against records by mtime}';

    protected $description = 'Reconcile registered BeamUx entries against on-disk files by mtime (config-gated, direction-configurable, OFF by default).';

    public function handle(UpdateFromNewer $batch): int
    {
        if (! $batch->enabled()) {
            $this->components->warn(
                'update-from-newer is OFF (beam.ux.update_from_newer.enabled=false) — no disk edits flow back. Enable it to run.',
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

        $this->components->info(sprintf(
            'Direction %s · %d updated · %d unchanged.',
            $result['direction'],
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
