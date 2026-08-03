<?php

namespace Splicewire\Beam\Ux\Disk;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Inference\InferDraftSchema;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * The **`register-from-disk` batch** (charter S8, `beamux-build/issues/05`) — the PAID `splicewire/*`
 * operator-run operation that bootstraps a BeamUx tree from an existing on-disk one. It scans a target
 * directory for **every registered body format's** file (format-aware, ADR-0164 — NO LONGER tsx-only:
 * an `.mdx` file registers exactly as a `.tsx` one), skips files already in the DB, materializes a
 * {@see BeamUxEntry} for each new one, infers its `type` + `namespace` from the path (the reverse of
 * S2's {@see DefaultPlacement}), writes the body THROUGH the resolved
 * free-beam-core {@see StorageDriver}, and — for a `component` — runs S9's
 * {@see InferDraftSchema} at import so the fresh entry arrives editable.
 *
 * **Explicit operator batch, never a watcher (the load-bearing rule).** This runs ONLY when an operator
 * invokes it — there is no ambient filesystem watcher (rejected: it races versioning + the build). Every
 * inbound disk→DB flow is one of these deliberate batches.
 *
 * **Idempotent.** A file whose derived envelope (`namespace` + `slug`) already resolves to a record is
 * SKIPPED — re-running the batch registers only what is new, never duplicating or clobbering.
 *
 * **Vendor seam (ADR-0092).** The batch orchestration over the storage port is paid `splicewire/*`; the
 * particle + `ParticleWriter` records the body rides are free beam-core, untouched.
 */
class RegisterEntriesFromDisk
{
    public function __construct(
        protected RegisterFromDisk $disk,
        protected StorageDriverResolver $drivers,
        protected InferDraftSchema $inference,
    ) {}

    /**
     * Scan `$root` and register every recognized-format file not yet in the DB. Returns the outcome:
     * the entries created, the disk-relative paths skipped as already-present, and the paths ignored as
     * an unrecognized (non-body) format.
     *
     * @return array{created: array<int, BeamUxEntry>, skipped: array<int, string>, ignored: array<int, string>}
     */
    public function scan(string $root): array
    {
        $root = rtrim($root, '/');

        $created = [];
        $skipped = [];
        $ignored = [];

        if (! is_dir($root)) {
            return compact('created', 'skipped', 'ignored');
        }

        foreach ($this->files($root) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($root)), '/');

            if (! $this->disk->recognizes($relative)) {
                $ignored[] = $relative;

                continue;
            }

            $envelope = $this->disk->envelopeForPath($relative);
            if ($envelope === null) {
                $ignored[] = $relative;

                continue;
            }

            if ($this->existing($envelope) !== null) {
                $skipped[] = $relative;

                continue;
            }

            $created[] = $this->register($envelope, (string) file_get_contents($absolute));
        }

        return compact('created', 'skipped', 'ignored');
    }

    /**
     * Materialize one entry from a derived envelope + its raw source: create the record, write the body
     * through the resolved StorageDriver, and run S9 draft-schema inference (a no-op for
     * page/layout/template — only a `component` gets a draft).
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     */
    protected function register(array $envelope, string $source): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => $envelope['slug'],
            'type' => $envelope['type'],
            'namespace' => $envelope['namespace'],
            'format' => $envelope['format'],
        ]);

        // The body rides the free-beam-core StorageDriver (ParticleWriter under the default Stacked
        // driver) — the paid batch selects the driver, beam-core does the versioned write.
        $driver = $this->drivers->resolve($entry);
        $item = $driver->write('', ['source' => $source], $entry->namespace);

        if ($entry->particle_id === null && $item->key !== '') {
            $entry->particle_id = $item->key;
            $entry->save();
        }

        // S9 at import: a fresh `component` arrives editable; page/layout/template are left untouched.
        $this->inference->forEntry($entry, $source, persist: true);

        return $entry->refresh();
    }

    /**
     * The record a derived envelope already resolves to (the idempotency key: `namespace` + `slug`), or
     * null when nothing is registered there yet.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     */
    protected function existing(array $envelope): ?BeamUxEntry
    {
        return BeamUxEntry::query()
            ->where('slug', $envelope['slug'])
            ->where('namespace', $envelope['namespace'])
            ->first();
    }

    /**
     * Every file under `$root`, recursively (depth-first). Directories and dotfiles are skipped by the
     * iterator; recognition of a body format happens in {@see scan()}.
     *
     * @return iterable<int, string> absolute file paths
     */
    protected function files(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }
}
