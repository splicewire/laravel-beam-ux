<?php

namespace Splicewire\Beam\Ux\Disk;

/**
 * Reads the RAW, frontmatter-stripped source of a disk-authored `.mdx` content file.
 *
 * The vite `@mdx-js` plugin compiles every `.mdx` at build time (regardless of a `?raw` query), so the
 * client can never obtain the original markdown source — a host that seeds an mdxeditor buffer with the
 * existing copy must read the file SERVER-SIDE. That read is generic (any beam host with disk-authored MDX
 * needs it), so it lives here rather than in each host's controller.
 *
 * The content root is configurable via `beam.ux.content_path` (default `resources/js/content`, matching a
 * Vite-served content dir); a null/absent file degrades to `null` (the caller renders its default).
 */
class RawMdxReader
{
    /**
     * @param  string  $root  the base-path-relative content root (where `{name}.mdx` files live)
     */
    public function __construct(private string $root) {}

    /**
     * The frontmatter-stripped raw source of the `{name}.mdx` file under the content root, or `null` if the
     * file is absent. `$name` may include sub-directory segments (e.g. `legal/privacy`); the `.mdx`
     * extension is implied. The leading `---` … `---` frontmatter block is stripped and the result trimmed.
     */
    public function read(string $name): ?string
    {
        $path = base_path($this->root.'/'.ltrim($name, '/').'.mdx');

        if (! is_file($path)) {
            return null;
        }

        $source = (string) file_get_contents($path);

        return trim((string) preg_replace('/^---\r?\n.*?\r?\n---\r?\n/s', '', $source));
    }
}
