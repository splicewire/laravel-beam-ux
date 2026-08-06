<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * `php artisan splicewire:beam:ux:enrich-page-schemas {--namespace=} {--schema-json=} {--root=}` — the
 * GENERIC encode→stamp batch (F02).
 *
 * `register-from-disk` stores an `.mdx` page's raw `source` verbatim (it bypasses the codec's
 * `encode()`), and a `page` gets no schema (S9 prop-inference is tsx-only). So a `page`'s authoring
 * editor renders "This region has no schema" and its body is unstructured. This batch fixes both, for
 * every `.mdx`-format `page` under a namespace: it re-reads the page's git-tracked disk file, encodes it
 * to the codec's structured `{frontmatter, content}` shape (flattened to editable fields via
 * {@see MdxBody::encode}), writes it through the SAME StorageDriver the editor round-trips, and stamps an
 * inline page-chrome schema onto `schema_ref`.
 *
 * The batch is generic; only the NAMESPACE + the page-chrome SCHEMA are host-specific, so both are
 * injectable (`--namespace`, default `config('beam.ux.namespace')`; `--schema-json`, default a minimal
 * heading/intent/content shape). The disk path is derived from each entry's placement (not a hard-coded
 * `{root}/{namespace}/page/{slug}.mdx`), so a host that relocates its disk layout needs no change here.
 * Idempotent — safe to re-run after `register-from-disk`; the fields live in the disk frontmatter, this
 * only projects them into the particle body + schema.
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `enrich-page-schemas`.
 */
class EnrichPageSchemasCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:enrich-page-schemas
        {--namespace= : Disk-grouping namespace (default config beam.ux.namespace)}
        {--schema-json= : JSON page-chrome schema to stamp (default a minimal heading/intent/content shape)}
        {--root= : The mirror-disk root the page files were registered from (default the mirror disk)}';

    protected $description = 'Enrich .mdx page entries with a structured body + inline schema (generic encode→stamp; namespace + schema injectable).';

    /** The default editable page-chrome shape — the fields an author edits (and the frontend reshell reads). */
    private const DEFAULT_SCHEMA = [
        'type' => 'object',
        'title' => 'Page',
        'properties' => [
            'heading' => ['type' => 'string', 'title' => 'Heading'],
            'intent' => ['type' => 'string', 'title' => 'Intent (one-line purpose)'],
            'content' => ['type' => 'string', 'title' => 'Content (MDX prose)'],
        ],
    ];

    public function handle(StorageDriverResolver $drivers, PlacementResolver $placements): int
    {
        $namespace = $this->namespace();
        $schema = $this->schema();
        if ($schema === null) {
            return self::FAILURE;
        }

        $root = $this->root();
        if ($root === null) {
            $this->components->error('No mirror-disk root — pass --root or configure beam.ux.storage.mirror_disk.');

            return self::FAILURE;
        }

        $entries = BeamUxEntry::query()
            ->where('namespace', $namespace)
            ->where('type', UxType::Page->value)
            ->get();

        if ($entries->isEmpty()) {
            $this->components->warn("No page entries under [{$namespace}] — run splicewire:beam:ux:register-from-disk first.");

            return self::SUCCESS;
        }

        $enriched = 0;

        foreach ($entries as $entry) {
            // Derive the disk path from the entry's OWN placement (reverse of S2), not a hard-coded shape.
            $relative = $placements->resolve($entry)->pathFor($entry);
            $file = rtrim($root, '/').'/'.ltrim($relative, '/');

            // This batch only enriches .mdx pages — a .tsx page is a Puck-composed body (structural, no
            // flat page-chrome schema), enriched via the bridge, not this encode path.
            if (! str_ends_with($file, '.mdx') || ! is_file($file)) {
                continue;
            }

            $encoded = MdxBody::encode((string) file_get_contents($file));
            $frontmatter = (array) ($encoded[MdxBody::FRONTMATTER_KEY] ?? []);
            $body = array_merge($frontmatter, ['content' => trim((string) ($encoded[MdxBody::CONTENT_KEY] ?? ''))]);

            $driver = $drivers->resolve($entry);
            $written = $driver->write((string) ($entry->particle_id ?? ''), $body, $entry->namespace);

            $entry->particle_id ??= $written->key;
            $entry->schema_ref = json_encode($schema);
            $entry->save();

            $enriched++;
            $this->components->info("Enriched [{$entry->slug}] — ".count($body).' fields + page schema.');
        }

        $this->components->info("{$enriched} page(s) enriched.");

        return self::SUCCESS;
    }

    /** The disk-grouping namespace — the `--namespace` option, else `config('beam.ux.namespace')`. */
    private function namespace(): string
    {
        $option = $this->option('namespace');

        return is_string($option) && $option !== '' ? $option : (string) config('beam.ux.namespace', '');
    }

    /**
     * The page-chrome schema to stamp — decoded from `--schema-json` when passed, else the default shape.
     * Returns null (and errors) on invalid JSON.
     *
     * @return array<string, mixed>|null
     */
    private function schema(): ?array
    {
        $json = $this->option('schema-json');
        if (! is_string($json) || $json === '') {
            return self::DEFAULT_SCHEMA;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->components->error('--schema-json is not valid JSON.');

            return null;
        }

        return $decoded;
    }

    /** The mirror-disk filesystem root — the `--root` option, else `beam.ux.storage.mirror_disk`'s root. */
    private function root(): ?string
    {
        $option = $this->option('root');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        $disk = config('beam.ux.storage.mirror_disk');
        if (! is_string($disk) || $disk === '') {
            return null;
        }

        $root = config("filesystems.disks.{$disk}.root");

        return is_string($root) && $root !== '' ? $root : null;
    }
}
