<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `php artisan splicewire:beam:ux-register-from-disk {path}` — the operator-run **`register-from-disk`**
 * batch (charter S8). Scans `{path}` for every registered body-format file NOT yet in the DB, creates a
 * {@see BeamUxEntry} for each, infers `type` + `namespace` from the path
 * (reverse of S2's placement), writes the body through the free-beam-core StorageDriver, and runs S9
 * draft-schema inference at import so a freshly-registered `component` arrives editable. Idempotent —
 * a file already registered is skipped.
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `register-from-disk`.
 */
class RegisterFromDiskCommand extends Command
{
    protected $signature = 'splicewire:beam:ux-register-from-disk {path : The directory to scan for un-registered body files}';

    protected $description = 'Register on-disk BeamUx bodies (every format) not yet in the DB; infer type+namespace from path; run S9 draft inference at import.';

    public function handle(RegisterEntriesFromDisk $batch): int
    {
        $path = (string) $this->argument('path');

        if (! is_dir($path)) {
            $this->components->error("Not a directory: {$path}");

            return self::FAILURE;
        }

        $result = $batch->scan($path);

        foreach ($result['created'] as $entry) {
            $draft = $entry->schema_is_draft ? ' (draft schema)' : '';
            $this->components->info("Registered [{$entry->namespace}].{$entry->slug} as {$entry->type?->value}{$draft}");
        }

        $this->components->info(sprintf(
            '%d registered · %d skipped (already present) · %d ignored (not a body format).',
            count($result['created']),
            count($result['skipped']),
            count($result['ignored']),
        ));

        return self::SUCCESS;
    }
}
