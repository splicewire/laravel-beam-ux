<?php

namespace Splicewire\Beam\Ux\Nav;

use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the CONTENT-NAV data `splicewire:beam:ux:seed-nav` seeds — data-driven, never a hard-coded
 * array. Resolution priority (highest first, F02):
 *
 *   1. **`config('beam.ux.nav')`** — an explicit host override. Highest so a host can pin the nav.
 *   2. **`resources/beam-ux/nav.{yml,yaml,json}`** on disk — an authored nav file if present.
 *   3. **DERIVED from registered entries' frontmatter** (`segment`/`realm`/`nav_order`) — the default
 *      when neither above is set. A fresh site seeds its nav with NO bespoke PHP: the entries already
 *      carry `segment`/`realm`/`nav_order` (from disk frontmatter via register-from-disk), so the nav is
 *      DERIVED from them, not authored twice.
 *
 * A nav ROW is a normalized shape `{slug, segment, title, type, realm, nav_order}`. Both the config and
 * the disk forms accept two authoring shapes:
 *   - LIST-of-maps: `[{slug, segment, title, type, realm, nav_order?}, …]`
 *   - ASSOC (slug ⇒ row-without-slug): `{home: {segment: '/', title: 'Home', type: 'page', realm: 'site'}}`
 *
 * `namespace` is passed by the command (the disk-grouping coordinate, not carried in a nav row).
 */
class NavSource
{
    /**
     * Resolve the ordered nav rows for a namespace, following the config → disk → derived priority.
     *
     * @return list<array{entry_id?: string, slug: string, segment: ?string, title: ?string, type: string, realm: string, nav_order: ?int}>
     */
    public function resolve(string $namespace): array
    {
        if (($config = config('beam.ux.nav')) !== null && is_array($config)) {
            return $this->normalize($config);
        }

        if (($disk = $this->fromDisk()) !== null) {
            return $this->normalize($disk);
        }

        return $this->deriveFromEntries($namespace);
    }

    /**
     * True when the source is DERIVED: NO nav is DECLARED, i.e. `config('beam.ux.nav')` is null and no
     * `nav.{yml,yaml,json}` file EXISTS. Decided by presence, never by whether a declared source decodes (launch 76): a
     * present-but-unusable source (a nav.json with a trailing comma; an empty, comments-only or scalar nav.yml; a
     * non-array config value) is a DECLARED source gone wrong, so an empty result from it is an authoring error the
     * caller must report, not the benign "no entry carries nav data yet".
     */
    public function isDerived(string $namespace): bool
    {
        return config('beam.ux.nav') === null && $this->navFile() === null;
    }

    /**
     * Read `resources/beam-ux/nav.{yml,yaml,json}` if present, relative to the mirror-disk root (else the
     * `resource_path('beam-ux')` default). Returns the raw decoded array, or null when no file exists OR the file
     * does not decode to an array (see {@see isDerived()}, which tells those two apart by presence).
     *
     * @return array<mixed>|null
     */
    private function fromDisk(): ?array
    {
        $file = $this->navFile();
        if ($file === null) {
            return null;
        }

        $raw = (string) file_get_contents($file);
        $decoded = str_ends_with($file, '.json') ? json_decode($raw, true) : Yaml::parse($raw);

        return is_array($decoded) ? $decoded : null;
    }

    /** The first `nav.{yml,yaml,json}` that exists under {@see navRoot()}, or null when none does. */
    private function navFile(): ?string
    {
        $root = $this->navRoot();

        foreach (['yml', 'yaml', 'json'] as $ext) {
            if (is_file($file = $root.'/nav.'.$ext)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The dir a `nav.{yml,json}` is looked up in. The mirror-disk root when a git-tracked mirror disk is
     * configured (bodies + nav.yml then co-locate under it), else `resource_path('beam-ux')` — the estate's
     * canonical author-source dir (where `register-from-disk` reads page files from), so a fresh host that
     * drops `resources/beam-ux/nav.yml` is found with ZERO config (beam-install-turnkey trap 2). It was
     * `base_path()` before, which never matched the documented `resources/beam-ux/` location.
     */
    private function navRoot(): string
    {
        $disk = config('beam.ux.storage.mirror_disk');
        if (is_string($disk) && $disk !== '') {
            $root = config("filesystems.disks.{$disk}.root");
            if (is_string($root) && $root !== '') {
                return rtrim($root, '/');
            }
        }

        return rtrim(resource_path('beam-ux'), '/');
    }

    /**
     * Derive nav rows from registered entries (all disk namespaces by default) — the frontmatter-carried
     * `segment`/`realm`/`nav_order`. Only routable pages that carry a `segment` are nav rows; ordered by
     * `nav_order` (then slug for a stable tiebreak). This is the "no bespoke PHP" default: the disk
     * frontmatter is the single source, projected here into nav. A non-empty namespace explicitly
     * scopes the projection; the default empty selector reads the host-wide content tree. Existing
     * entry identity travels with derived rows so seeding never copies them into the selector namespace.
     *
     * @return list<array{entry_id?: string, slug: string, segment: ?string, title: ?string, type: string, realm: string, nav_order: ?int}>
     */
    private function deriveFromEntries(string $namespace): array
    {
        return BeamUxEntry::query()
            ->when($namespace !== '', fn ($query) => $query->where('namespace', $namespace))
            ->where('type', 'page')
            ->whereNotNull('segment')
            ->where('segment', '!=', '')
            ->get()
            ->map(fn (BeamUxEntry $e): array => [
                'entry_id' => (string) $e->getKey(),
                'slug' => (string) $e->slug,
                'segment' => $e->segment,
                'title' => $e->title,
                'type' => $e->type?->value ?? 'page',
                'realm' => $e->realm ?? BeamUxEntry::REALM_SITE,
                'nav_order' => $e->nav_order !== null ? (int) $e->nav_order : null,
            ])
            ->sortBy([['nav_order', 'asc'], ['slug', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Normalize either authoring shape (list-of-maps OR slug⇒row assoc) into ordered nav rows.
     *
     * @param  array<mixed>  $raw
     * @return list<array{entry_id?: string, slug: string, segment: ?string, title: ?string, type: string, realm: string, nav_order: ?int}>
     */
    private function normalize(array $raw): array
    {
        $rows = [];

        foreach ($raw as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            // Assoc shape: string key IS the slug; list shape: the row carries its own `slug`.
            $slug = is_string($key) ? $key : (string) ($value['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $rows[] = [
                'slug' => $slug,
                'segment' => isset($value['segment']) ? (string) $value['segment'] : null,
                'title' => isset($value['title']) ? (string) $value['title'] : null,
                'type' => (string) ($value['type'] ?? 'page'),
                'realm' => (string) ($value['realm'] ?? BeamUxEntry::REALM_SITE),
                'nav_order' => isset($value['nav_order']) ? (int) $value['nav_order'] : null,
            ];
        }

        return $rows;
    }
}
