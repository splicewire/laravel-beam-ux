<?php

namespace Splicewire\Beam\Ux\Provenance;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * The one-time repair for rows register-from-disk created unstamped (registry-docs ticket 10, ruling of ticket 09).
 *
 * Before ticket 10, `RegisterEntriesFromDisk::register()` hashed the raw file and then stored its blocks lowering, so a
 * row first registered on a host that holds MDX as blocks read edited at birth and never re-asserted (69 of 109 origin
 * rows on www, 2026-11-04). The fix stamps new rows correctly; this re-stamps the rows already born that way.
 *
 * It re-stamps a row ONLY when all four hold, and each one is a proof rather than a heuristic:
 *
 * 1. the row is not pristine (a pristine row needs nothing);
 * 2. `asserted_hash == hash(title, asStored(codec, originFile))`: the origin asserted exactly the file now on disk, so
 *    the old stamp is the defect's and not a stale assertion of an earlier file;
 * 3. the stored print `===` the file's registration ({@see RegisterEntriesFromDisk::asWritten()}): the row holds that
 *    file's lowering and no authored word;
 * 4. no draft is pending.
 *
 * Then `asserted_hash = hash(title, currentBody)`, the value {@see Reasserter::isPristine()} compares, and nothing else:
 * no body, version, placement, origin or timestamp moves. A row that fails a guard is listed with its reason and never
 * written, so an edited row stays edited (ADR-0215 §2) and its remedy stays the owner's.
 *
 * Dry-run unless `$apply`. The write is a compare-and-set on the old hash, so a row stamped by anything else between the
 * plan and the write is left alone.
 */
final class RegisteredRowRestamp
{
    public const RESTAMP = 'would re-stamp';

    public function __construct(
        private RegisterFromDisk $disk,
        private Reasserter $reasserter,
        private EntryPublication $publication,
    ) {}

    /**
     * @param  list<array{path?: string, ignore?: list<string>}>  $sources  `beam.docs.sources`-shaped; a relative path
     *                                                                      resolves against the host's base path
     * @param  bool  $unreached  also list every seeded or imported row no source file reached (the whole-host mode)
     * @return array{rows: list<array{id: string, row: string, origin: ?string, file: ?string, verdict: string}>, restamped: int, missing: list<string>}
     */
    public function run(array $sources, bool $apply = false, bool $unreached = false): array
    {
        [$reached, $missing] = $this->reach($sources);

        $rows = [];
        $restamp = [];
        foreach ($reached as [$entry, $absolute, $file]) {
            $verdict = $this->verdict($entry, (string) file_get_contents($absolute));
            $rows[] = $this->row($entry, $file, $verdict);
            if ($verdict === self::RESTAMP) {
                $restamp[] = $entry;
            }
        }

        if ($unreached) {
            $seen = array_map(fn (array $hit): string => (string) $hit[0]->getKey(), $reached);
            $others = BeamUxEntry::query()->whereNotNull('origin')->whereNotIn('id', $seen)->orderBy('namespace')->orderBy('slug')->get();
            foreach ($others as $entry) {
                if (! self::asserts((string) $entry->origin)) {
                    continue; // a cms row is never judged, as docs.diverged does not judge it
                }
                $rows[] = $this->row($entry, null, $this->reasserter->isPristine($entry) ? 'pristine' : 'no origin file under the declared sources');
            }
        }

        $restamped = 0;
        if ($apply && $restamp !== []) {
            $restamped = (new BeamUxEntry)->getConnection()->transaction(function () use ($restamp): int {
                $count = 0;
                foreach ($restamp as $entry) {
                    $current = $this->reasserter->currentBody($entry);
                    if ($current === null) {
                        continue;
                    }
                    // Compare-and-set, through the query builder: only the hash column, no model events, no timestamps.
                    $count += $entry->getConnection()->table($entry->getTable())
                        ->where($entry->getKeyName(), $entry->getKey())
                        ->where('asserted_hash', (string) $entry->asserted_hash)
                        ->update(['asserted_hash' => Provenance::hash($entry->title, $current)]);
                }

                return $count;
            });
        }

        return ['rows' => $rows, 'restamped' => $restamped, 'missing' => $missing];
    }

    /** The four guards, in order; the first that refuses names the row's reason. */
    private function verdict(BeamUxEntry $entry, string $source): string
    {
        if (! self::asserts((string) ($entry->origin ?? ''))) {
            return 'not a seeded or imported row (origin '.($entry->origin ?? 'none').')';
        }
        if ($this->reasserter->isPristine($entry)) {
            return 'pristine';
        }
        if (Provenance::hash($entry->title, Provenance::asStored($entry->codec(), $source)) !== (string) $entry->asserted_hash) {
            return 'asserted hash is not this file (an edit, or the file moved on since registration)';
        }
        $current = $this->reasserter->currentBody($entry);
        if ($current === null) {
            return 'stored body unreadable';
        }
        if ($current !== RegisterEntriesFromDisk::asWritten($entry, $source)) {
            return 'stored body is not this file\'s registration (an authored change)';
        }
        if ($this->publication->state($entry)->draftPending) {
            return 'draft pending';
        }

        return self::RESTAMP;
    }

    /**
     * Every recognized file under every source that resolves to an existing row, first source first, the way the
     * importer itself derives a file's coordinate. A row two sources reach is judged once, against the first.
     *
     * @return array{0: list<array{0: BeamUxEntry, 1: string, 2: string}>, 1: list<string>}
     */
    private function reach(array $sources): array
    {
        $reached = [];
        $ids = [];
        $missing = [];
        foreach ($sources as $source) {
            $declared = (string) ($source['path'] ?? '');
            $root = rtrim(str_starts_with($declared, '/') ? $declared : base_path($declared), '/');
            if ($declared === '' || ! is_dir($root)) {
                $missing[] = $declared;

                continue;
            }
            $ignore = array_values(array_map('strval', (array) ($source['ignore'] ?? [])));
            $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)), false);
            $paths = array_map(fn ($file) => $file->getPathname(), array_filter($files, fn ($file) => $file->isFile()));
            sort($paths);
            foreach ($paths as $absolute) {
                $relative = ltrim(substr($absolute, strlen($root)), '/');
                if (RegisterEntriesFromDisk::ignores($ignore, $relative) || ! $this->disk->recognizes($relative)
                    || ($envelope = $this->disk->envelopeForPath($relative)) === null) {
                    continue;
                }
                $entry = BeamUxEntry::query()->where('slug', $envelope['slug'])->where('namespace', $envelope['namespace'])->first();
                if ($entry === null || isset($ids[(string) $entry->getKey()])) {
                    continue;
                }
                $ids[(string) $entry->getKey()] = true;
                $reached[] = [$entry, $absolute, $declared.'/'.$relative];
            }
        }

        return [$reached, $missing];
    }

    /** @return array{id: string, row: string, origin: ?string, file: ?string, verdict: string} */
    private function row(BeamUxEntry $entry, ?string $file, string $verdict): array
    {
        return [
            'id' => (string) $entry->getKey(),
            'row' => ($entry->namespace ? "{$entry->namespace}." : '').$entry->slug,
            'origin' => $entry->origin,
            'file' => $file,
            'verdict' => $verdict,
        ];
    }

    private static function asserts(string $origin): bool
    {
        return str_starts_with($origin, Provenance::DISK_PREFIX)
            || str_starts_with($origin, Provenance::PACKAGE_PREFIX)
            || str_starts_with($origin, Provenance::PACK_PREFIX);
    }
}
