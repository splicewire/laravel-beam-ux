<?php

namespace Splicewire\Beam\Ux\Provenance;

use FilesystemIterator;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Throwable;

/**
 * The one-time provenance backfill (docs-walkthrough DOCS-06b, ADR-0215 §2): rows created before the provenance
 * migration carry no `origin` and are never re-asserted, so live hosts never converge on fixed stubs and files. This
 * infers an unstamped row's origin by matching its STORED body to where it came from:
 *
 * - a disk file at the row's coordinate under a declared source: its current content, or a prior version recorded in
 *   the host's history (the sha256 of that version as stored), → `disk:<relative>`;
 * - a package template registered at the coordinate, current or a shipped prior one, EXACT apart from `{{ token }}`
 *   spans ({@see ProvenanceMatcher}), → that template's origin.
 *
 * A match is stamped `origin` + `asserted_hash` over the title and the stored body, which makes it pristine, so the next
 * seed re-asserts it to the current source. No match stays unstamped: an edited or foreign row is never re-asserted.
 * Only those two columns are written, and only on rows with no origin; a stamped row is never re-stamped.
 */
final class ProvenanceBackfill
{
    public function __construct(
        private StorageDriverResolver $drivers,
        private RegisterFromDisk $disk,
        private ProvenanceTemplates $templates,
    ) {}

    /**
     * What the backfill would stamp, without writing.
     *
     * @param  list<array{path?: string, ignore?: list<string>}>  $diskSources  `beam.docs.sources`-shaped
     * @param  array<string, list<string>>  $diskHistory  relative path => sha256 of each recorded prior version as stored
     * @return list<array{id: string, namespace: ?string, slug: string, title: ?string, origin: ?string, via: string}>
     */
    public function plan(array $diskSources, array $diskHistory = []): array
    {
        if (! Schema::hasColumn((new BeamUxEntry)->getTable(), 'origin')) {
            throw new RuntimeException('Run the provenance migration (add_provenance_to_beam_ux_entries) before the backfill.');
        }

        $files = $this->diskFiles($diskSources);
        $plan = [];

        foreach (BeamUxEntry::query()->whereNull('origin')->whereNotNull('particle_id')->orderBy('created_at')->get() as $entry) {
            $stored = $this->storedBody($entry);
            if ($stored === null) {
                continue;
            }
            [$origin, $via] = $this->match($entry, $stored, $files, $diskHistory);
            $plan[] = [
                'id' => (string) $entry->getKey(),
                'namespace' => $entry->namespace,
                'slug' => (string) $entry->slug,
                'title' => $entry->title,
                'origin' => $origin,
                'via' => $via,
            ];
        }

        return $plan;
    }

    /**
     * Stamp every matched row of a plan. Re-checks each row is still unstamped and re-reads its body, so a row edited
     * between plan and apply is stamped over what it holds NOW only if it still matches.
     *
     * @param  list<array{id: string, origin: ?string}>  $plan
     * @return int rows stamped
     */
    public function apply(array $plan): int
    {
        $stamped = 0;
        foreach ($plan as $row) {
            if ($row['origin'] === null) {
                continue;
            }
            $entry = BeamUxEntry::query()->whereKey($row['id'])->whereNull('origin')->first();
            $stored = $entry !== null ? $this->storedBody($entry) : null;
            if ($entry === null || $stored === null) {
                continue;
            }
            // A raw update, not save(): only the two provenance columns move, and no observer, version or event fires.
            $stamped += BeamUxEntry::query()->whereKey($entry->getKey())->whereNull('origin')->update([
                'origin' => $row['origin'],
                'asserted_hash' => Provenance::hash($entry->title, $stored),
            ]);
        }

        return $stamped;
    }

    /**
     * @param  array<string, array{relative: string, absolute: string}>  $files
     * @param  array<string, list<string>>  $history
     * @return array{0: ?string, 1: string}
     */
    private function match(BeamUxEntry $entry, string $stored, array $files, array $history): array
    {
        $codec = $entry->codec();
        $file = $files[$this->key($entry->namespace, (string) $entry->slug)] ?? null;

        if ($file !== null) {
            if (Provenance::asStored($codec, (string) file_get_contents($file['absolute'])) === $stored) {
                return [Provenance::disk($file['relative']), 'disk file'];
            }
            if (in_array(hash('sha256', $stored), $history[$file['relative']] ?? [], true)) {
                return [Provenance::disk($file['relative']), 'disk history'];
            }
        }

        foreach ($this->templates->at($entry->namespace, (string) $entry->slug) as $template) {
            if (ProvenanceMatcher::matches($template->template, $stored, $codec)) {
                return [$template->origin, $template->label];
            }
        }

        return [null, 'no match'];
    }

    /**
     * Every recognized file under the declared sources, by the coordinate the importer gives it (the same envelope and
     * idempotency key as {@see RegisterEntriesFromDisk::plan()}); the first file wins a coordinate, as in a scan.
     *
     * @param  list<array{path?: string, ignore?: list<string>}>  $sources
     * @return array<string, array{relative: string, absolute: string}>
     */
    public function diskFiles(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            $declared = (string) ($source['path'] ?? '');
            $root = rtrim(str_starts_with($declared, '/') ? $declared : base_path($declared), '/');
            if ($declared === '' || ! is_dir($root)) {
                continue;
            }
            $ignore = array_values(array_map('strval', (array) ($source['ignore'] ?? [])));
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
                if (RegisterEntriesFromDisk::ignores($ignore, $relative) || ! $this->disk->recognizes($relative)) {
                    continue;
                }
                $envelope = $this->disk->envelopeForPath($relative);
                if ($envelope !== null) {
                    $out[$this->key($envelope['namespace'], $envelope['slug'])] ??= ['relative' => $relative, 'absolute' => $file->getPathname()];
                }
            }
        }

        return $out;
    }

    public function storedBody(BeamUxEntry $entry): ?string
    {
        try {
            $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);

            return $item === null ? null : $entry->codec()->decode($item->body);
        } catch (Throwable) {
            return null;
        }
    }

    private function key(?string $namespace, string $slug): string
    {
        return ($namespace ?? '').'|'.$slug;
    }
}
