<?php

namespace Splicewire\Beam\Ux\Disk;

use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * The **`update-from-newer` batch** (charter S8, `beamux-build/issues/05`) — the PAID `splicewire/*`
 * operator-run operation that reconciles an already-registered entry against its on-disk file by
 * **mtime**, using the free-beam-core {@see StorageDriver::staleness()} seam.
 *
 * **OFF by default (the load-bearing rule).** Gated on `config('beam.ux.update_from_newer.enabled',
 * false)` — disk-side edits flow back ONLY on an explicit operator command with the gate turned on.
 * There is NO ambient watcher; the default-off gate is the second guard on the same "explicit inbound
 * only" invariant `register-from-disk` holds.
 *
 * **Configurable direction.** `config('beam.ux.update_from_newer.direction')` (default `disk-to-record`)
 * chooses which side wins when they diverge:
 *  - `disk-to-record` — a NEWER disk file's body is pulled into the source-of-record (the inbound sync).
 *  - `record-to-disk` — a NEWER record re-materializes onto disk (the outbound re-projection).
 * Either way the batch acts only when the chosen source is strictly newer (`staleness` sign); an equal
 * or already-current pair is left untouched.
 *
 * **Vendor seam (ADR-0092).** The batch + direction policy are paid; the particle/disk drivers it moves
 * bodies between are free beam-core.
 */
class UpdateFromNewer
{
    public const DIRECTION_DISK_TO_RECORD = 'disk-to-record';

    public const DIRECTION_RECORD_TO_DISK = 'record-to-disk';

    public function __construct(
        protected RegisterFromDisk $disk,
        protected StorageDriverResolver $drivers,
        protected PlacementResolver $placements,
    ) {}

    /** Is the config gate on? Disk edits flow back ONLY when this is true (default OFF). */
    public function enabled(): bool
    {
        return (bool) config('beam.ux.update_from_newer.enabled', false);
    }

    /** The configured direction (default `disk-to-record`). */
    public function direction(): string
    {
        return (string) config('beam.ux.update_from_newer.direction', self::DIRECTION_DISK_TO_RECORD);
    }

    /**
     * Reconcile every registered entry under a disk `$root` against its file by mtime. A no-op (returns
     * an empty `updated` set, `enabled: false`) when the config gate is OFF — the default — so disk-side
     * edits never flow back silently.
     *
     * `$sources` maps a disk-relative path → the raw source string currently on disk (the operator/test
     * supplies what the filesystem holds; the batch decides whether it is newer and, if so, writes it
     * through the source-of-record driver). `$diskMtimes` maps the same path → its filesystem mtime.
     *
     * @param  array<string, string>  $sources  disk-relative path → current on-disk source
     * @param  array<string, int>  $diskMtimes  disk-relative path → filesystem mtime (epoch)
     * @return array{enabled: bool, direction: string, updated: array<int, BeamUxEntry>, skipped: array<int, BeamUxEntry>}
     */
    public function run(array $sources, array $diskMtimes): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false, 'direction' => $this->direction(), 'updated' => [], 'skipped' => []];
        }

        $direction = $this->direction();
        $updated = [];
        $skipped = [];

        foreach (BeamUxEntry::all() as $entry) {
            $path = $this->placements->resolve($entry)->pathFor($entry);

            if (! array_key_exists($path, $sources) || ! array_key_exists($path, $diskMtimes)) {
                $skipped[] = $entry;

                continue;
            }

            if ($this->reconcile($entry, $sources[$path], $diskMtimes[$path], $direction)) {
                $updated[] = $entry;
            } else {
                $skipped[] = $entry;
            }
        }

        return ['enabled' => true, 'direction' => $direction, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Reconcile ONE entry against its on-disk source+mtime under the chosen direction. Returns true when
     * the entry's body was moved (disk pulled in, or record re-projected), false when nothing was newer.
     */
    protected function reconcile(BeamUxEntry $entry, string $diskSource, int $diskMtime, string $direction): bool
    {
        $driver = $this->drivers->resolve($entry);
        $key = (string) ($entry->particle_id ?? '');

        // staleness: >0 candidate (disk) newer, 0 equal/unknown, <0 stored (record) newer.
        $staleness = $key === '' ? 1 : $driver->staleness($key, $diskMtime);

        if ($direction === self::DIRECTION_RECORD_TO_DISK) {
            // Outbound: the record wins only when it is strictly newer than disk.
            if ($staleness >= 0) {
                return false;
            }

            $stored = $key === '' ? null : $driver->read($key);
            $body = $stored?->body ?? [];
            $driver->write($key, $body, $entry->namespace);

            return true;
        }

        // Inbound (default): disk wins only when it is strictly newer than the record.
        if ($staleness <= 0) {
            return false;
        }

        $item = $driver->write($key, ['source' => $diskSource], $entry->namespace);

        if ($entry->particle_id === null && $item->key !== '') {
            $entry->particle_id = $item->key;
            $entry->save();
        }

        return true;
    }
}
