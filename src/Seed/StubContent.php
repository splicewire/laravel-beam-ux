<?php

namespace Splicewire\Beam\Ux\Seed;

/**
 * A seed body read from a **stub**, published-copy-first.
 *
 * Ticket 02 §4's shape: beam-ux ships the docs seed as an optionally-published stub and the install
 * seeds from it, rather than as a package-owned seeder that re-asserts itself. Publishing
 * (`vendor:publish --tag=beam-ux-docs`) is how a host customises what a fresh install seeds; not
 * publishing is how it gets the default. Either way the ROW, once created, belongs to the site — a
 * later edit to the stub changes nothing that already exists.
 *
 * This is established precedent rather than an exception: beam core already publishes `stubs/` under
 * `beam-stubs`, and `beam-client-runtime` publishes `api.ts`/`routes.ts` into `resource_path('js/lib/')`.
 * What no beam package ships is a *rendered* page — a Blade view or an Inertia page — and a stub is
 * neither.
 *
 * The frontmatter reader is deliberately flat `key: value`, the same tiny reader
 * {@see \Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk} carries, so the seed seam takes no dependency
 * on a YAML parser to read six scalars.
 */
class StubContent
{
    /**
     * @param  array<string, string>  $frontmatter
     */
    public function __construct(
        public readonly array $frontmatter,
        public readonly string $body,
    ) {}

    /**
     * Read `$name` from the host's published copy if present, else from the package's own stubs.
     * Returns null when neither exists — a host that published a partial set gets exactly the files it
     * published plus the package defaults for the rest.
     *
     * `$replacements` are `{{ key }}` placeholders substituted into the body. They exist because a seed
     * body cannot call `route()`: the API reference page has to point at `route('beam.openapi.yaml')`,
     * which is only knowable in PHP, and a hardcoded `/beam/openapi.yaml` in an MDX file would be a
     * second place the artifact path is written down.
     *
     * @param  array<string, string>  $replacements
     */
    public static function read(string $name, array $replacements = []): ?self
    {
        $published = function_exists('resource_path') ? resource_path("beam-ux/{$name}") : null;
        $packaged = __DIR__.'/../../stubs/'.$name;

        $path = ($published !== null && is_file($published)) ? $published : $packaged;

        if (! is_file($path)) {
            return null;
        }

        $raw = (string) file_get_contents($path);

        foreach ($replacements as $key => $value) {
            $raw = str_replace(['{{ '.$key.' }}', '{{'.$key.'}}'], $value, $raw);
        }

        return self::parse($raw);
    }

    /** Split a leading `---` … `---` block into flat `key: value` pairs, and keep the rest as the body. */
    public static function parse(string $raw): self
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?/s', $raw, $match)) {
            return new self([], $raw);
        }

        $fields = [];
        foreach (preg_split('/\r?\n/', $match[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $kv)) {
                $fields[$kv[1]] = trim($kv[2], " \t\"'");
            }
        }

        return new self($fields, $raw);
    }

    /**
     * The frontmatter as entry columns — only the keys that are actually set, so an unresolved field
     * falls to the model default rather than being written as null.
     *
     * The raw source (frontmatter included) stays the body: the frontmatter is part of what an author
     * wrote and what the disk mirror projects back out, exactly as it is for a disk-registered file.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $out = [];

        foreach (['title', 'segment'] as $key) {
            if (($this->frontmatter[$key] ?? '') !== '') {
                $out[$key] = $this->frontmatter[$key];
            }
        }

        if (isset($this->frontmatter['nav_order']) && is_numeric($this->frontmatter['nav_order'])) {
            $out['nav_order'] = (int) $this->frontmatter['nav_order'];
        }

        return $out;
    }
}
