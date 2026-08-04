<?php

namespace Splicewire\Beam\Ux\Disk;

use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Console\SyncFromDiskCommand;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The **reverse file→DB sync leg for Puck pages** (beam Model-B ticket 08) — the missing inbound half
 * of `PuckPageCodegen`'s outbound DB→disk projection.
 *
 * `PuckPageCodegen` compiles a `page`'s Puck `Data` body to a composed-JSX `.tsx` (generated OUTPUT);
 * before this, a hand/agent edit to that file was CLOBBERED on next publish, and
 * {@see UpdateFromNewer}'s inbound branch wrapped the raw text as `['source' => …]` — which for a
 * composed PAGE is a category error (it is not component source). This batch closes that hole: for a
 * `type=page` `.tsx` it shells to the Node {@see PuckBridge} to parse the file back into Puck Data and
 * persists THAT as the particle body through the same {@see StorageDriver} the
 * editor round-trips.
 *
 * **Reconcile policy — last-writer (v1).** For each registered page, disk wins only when its file mtime
 * is strictly newer than the record (the {@see StorageDriver::staleness} sign,
 * matching {@see UpdateFromNewer}'s `disk-to-record` default). No 3-way merge, no UI — a newer disk file
 * overwrites the body, a newer/equal record is left untouched. (Tickets 09/16 layer insert/remove +
 * conflict UI on top; v1 is last-writer only.)
 *
 * **Degrade-not-fabricate.** When the Node bridge is unavailable (no interpreter / script — see
 * {@see PuckBridge::available()}) OR the file has no page-shaped root, the page is SKIPPED (never
 * wrapped as raw source, never fabricated empty).
 *
 * **Explicit operator batch, never a watcher** — same rule as the sibling disk batches. This runs only
 * when an operator invokes {@see SyncFromDiskCommand}.
 */
class SyncPagesFromDisk
{
    public function __construct(
        protected PuckBridge $bridge,
        protected StorageDriverResolver $drivers,
        protected PlacementResolver $placements,
    ) {}

    /** Is the reverse page-sync usable? False (degrade) when the Node bridge is not present. */
    public function available(): bool
    {
        return $this->bridge->available();
    }

    /**
     * Reconcile every registered `page` against its on-disk `.tsx` by last-writer (mtime). `$sources`
     * maps a disk-relative path → the file's current source; `$diskMtimes` maps the same path → mtime.
     *
     * @param  array<string, string>  $sources
     * @param  array<string, int>  $diskMtimes
     * @return array{available: bool, updated: array<int, BeamUxEntry>, skipped: array<int, BeamUxEntry>}
     */
    public function run(array $sources, array $diskMtimes): array
    {
        if (! $this->available()) {
            return ['available' => false, 'updated' => [], 'skipped' => []];
        }

        $updated = [];
        $skipped = [];

        foreach (BeamUxEntry::all() as $entry) {
            if ($entry->type !== UxType::Page) {
                $skipped[] = $entry;

                continue;
            }

            $path = $this->placements->resolve($entry)->pathFor($entry);

            if (! array_key_exists($path, $sources) || ! array_key_exists($path, $diskMtimes)) {
                $skipped[] = $entry;

                continue;
            }

            if ($this->reconcile($entry, $sources[$path], $diskMtimes[$path])) {
                $updated[] = $entry;
            } else {
                $skipped[] = $entry;
            }
        }

        return ['available' => true, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Last-writer reconcile of ONE page: parse the disk `.tsx` to Puck Data via the Node bridge and
     * persist it as the body ONLY when the disk file is strictly newer than the record. Returns true
     * when the body was updated. A file with no page-shaped root (bridge → null) is a no-op skip.
     */
    protected function reconcile(BeamUxEntry $entry, string $diskSource, int $diskMtime): bool
    {
        $driver = $this->drivers->resolve($entry);
        $key = (string) ($entry->particle_id ?? '');

        // staleness: >0 disk newer, 0 equal/unknown, <0 record newer. Disk wins only when strictly newer.
        $staleness = $key === '' ? 1 : $driver->staleness($key, $diskMtime);
        if ($staleness <= 0) {
            return false;
        }

        // Node does the JSX→PuckData parse; PHP persists the resulting Data as the body.
        $data = $this->bridge->toPuck($diskSource);
        if ($data === null) {
            return false;
        }

        $item = $driver->write($key, $data, $entry->namespace);

        if ($entry->particle_id === null && $item->key !== '') {
            $entry->particle_id = $item->key;
            $entry->save();
        }

        return true;
    }
}
