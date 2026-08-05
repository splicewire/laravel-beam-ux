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
use Splicewire\Beam\Ux\Type\UxType;

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
        protected PuckBridge $bridge,
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

            $created[] = $this->register($envelope, (string) file_get_contents($absolute), $relative);
        }

        return compact('created', 'skipped', 'ignored');
    }

    /**
     * Materialize one entry from a derived envelope + its raw source: create the record, write the body
     * through the resolved StorageDriver, and run S9 draft-schema inference (a no-op for
     * page/layout/template — only a `component` gets a draft).
     *
     * The record is created with its **containment** ({@see containmentFor}: `realm`/`segment`/`nav_order`/
     * `title`) resolved from the file's own frontmatter (+ a config `realm` convention) — so a page lands
     * in the right realm and derives into nav with NO hand-authored `nav.yml` (ADR-0165; the derive leg
     * the nav-source priority chain already promised). `realm` is set at CREATE time so the model's
     * `creating` hook assigns the matching realm sitemap.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     */
    protected function register(array $envelope, string $source, string $relative = ''): BeamUxEntry
    {
        $entry = BeamUxEntry::create(array_merge([
            'slug' => $envelope['slug'],
            'type' => $envelope['type'],
            'namespace' => $envelope['namespace'],
            'format' => $envelope['format'],
        ], $this->containmentFor($source, $relative)));

        // The body rides the free-beam-core StorageDriver (ParticleWriter under the default Stacked
        // driver) — the paid batch selects the driver, beam-core does the versioned write.
        //
        // A `page` `.tsx` is a COMPOSED Puck page (PuckPageCodegen output), NOT component source — so
        // it is parsed back into Puck Data via the Node bridge and stored AS Data (ticket 08 deliverable
        // 4: "no re-encoding composed JSX as component source"). Any other body (a component, an mdx
        // page) — or a page the bridge cannot parse (degrade) — keeps its raw source.
        $body = $this->bodyFor($entry, $source);

        $driver = $this->drivers->resolve($entry);
        $item = $driver->write('', $body, $entry->namespace);

        if ($entry->particle_id === null && $item->key !== '') {
            $entry->particle_id = $item->key;
            $entry->save();
        }

        // S9 at import: a fresh `component` arrives editable; page/layout/template are left untouched.
        $this->inference->forEntry($entry, $source, persist: true);

        return $entry->refresh();
    }

    /**
     * The body to persist for a freshly-registered entry. A `page` `.tsx` (a composed Puck page) is
     * parsed to Puck `Data` via the Node bridge so it is stored structurally — never re-encoded as
     * component source. Everything else (a component, an mdx page) — or a page the bridge can't parse or
     * that has no bridge available (degrade-not-fabricate) — keeps its raw `['source' => …]`.
     *
     * @return array<string, mixed>
     */
    protected function bodyFor(BeamUxEntry $entry, string $source): array
    {
        if ($entry->type === UxType::Page
            && $entry->format?->value === 'tsx'
            && $this->bridge->available()
        ) {
            $data = $this->bridge->toPuck($source);
            if ($data !== null) {
                return $data;
            }
        }

        return ['source' => $source];
    }

    /**
     * The **containment** fields to stamp on a freshly-registered entry, resolved from the file itself so
     * nav DERIVES with no hand-authored `nav.yml`. Only resolved keys are returned (an unresolved field
     * falls to the model default — `realm` → `site`, `segment`/`nav_order`/`title` → null ⇒ not in nav).
     *
     * Realm priority (highest first): the file's own `realm:` frontmatter → the config `realm`
     * convention ({@see realmByConvention}) → omitted (the model's `site` default). `segment`/`nav_order`/
     * `title` come from frontmatter only — a URL is never guessed, so a page auto-surfaces into nav only
     * once it declares its `segment` (co-located in the page file, not a separate registry). The explicit
     * `beam.ux.nav` override and an authored `nav.yml` still outrank all of this at the NavSource seam.
     *
     * @return array<string, mixed>
     */
    protected function containmentFor(string $source, string $relative): array
    {
        $fm = $this->frontmatter($source);
        $out = [];

        $realm = $fm['realm'] ?? $this->realmByConvention($relative);
        if ($realm !== null && $realm !== '') {
            $out['realm'] = $realm;
        }

        if (isset($fm['segment']) && $fm['segment'] !== '') {
            $out['segment'] = $fm['segment'];
        }

        if (isset($fm['nav_order']) && $fm['nav_order'] !== '' && is_numeric($fm['nav_order'])) {
            $out['nav_order'] = (int) $fm['nav_order'];
        }

        if (isset($fm['title']) && $fm['title'] !== '') {
            $out['title'] = $fm['title'];
        }

        return $out;
    }

    /**
     * The scalar frontmatter of a body source — the leading `---` … `---` block parsed as flat
     * `key: value` pairs (the same shape `MdxBody` stores into the body). A `.tsx` page or any file with
     * no frontmatter block yields `[]`. Kept local (a tiny reader) so the disk seam takes no hard
     * dependency on the mdx package.
     *
     * @return array<string, string>
     */
    protected function frontmatter(string $source): array
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?/s', $source, $match)) {
            return [];
        }

        $fields = [];
        foreach (preg_split('/\r?\n/', $match[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $kv)) {
                $fields[$kv[1]] = trim($kv[2], " \t\"'");
            }
        }

        return $fields;
    }

    /**
     * The realm a file's DISK PATH maps to by convention, when its frontmatter declares none — the
     * config `beam.ux.realm_conventions` ordered map of `glob => realm` (fnmatch against the disk-relative
     * path, so a host scopes by segment, e.g. `*​/page/library-*` → `account`). First match wins; no
     * match ⇒ null (the model's `site` default applies). Default `[]` ⇒ pure frontmatter, no convention.
     */
    protected function realmByConvention(string $relative): ?string
    {
        $conventions = config('beam.ux.realm_conventions', []);
        if (! is_array($conventions)) {
            return null;
        }

        foreach ($conventions as $pattern => $realm) {
            if (is_string($pattern) && fnmatch($pattern, $relative)) {
                return (string) $realm;
            }
        }

        return null;
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
