# splicewire/laravel-beam-ux

The beam-ux authoring package (`Splicewire\Beam\Ux\*`) — the beam-tier authoring/orchestration UX layer over beam-core.

## Raw-MDX reader (`Splicewire\Beam\Ux\Disk\RawMdxReader`)

Reads the RAW, frontmatter-stripped source of a disk-authored `.mdx` content file, server-side.

A host that seeds an mdxeditor buffer with a page's existing copy can't read the source client-side: the vite
`@mdx-js` plugin compiles every `.mdx` at build time (regardless of a `?raw` query), so the compiled module —
not the markdown — is what ships. The read therefore has to happen on the server. That read is generic (any
beam host with disk-authored MDX needs it), so it lives here.

```php
use Splicewire\Beam\Ux\Disk\RawMdxReader;

public function __construct(private RawMdxReader $rawMdx) {}

// `{name}.mdx` under the content root; sub-dirs allowed; frontmatter stripped; null if absent.
$source = $this->rawMdx->read('legal/privacy'); // string|null
```

- **Contract:** `read(string $name): ?string` — the frontmatter-stripped, trimmed source of `{root}/{name}.mdx`,
  or `null` when the file is absent (the caller renders its default — degrade-not-fabricate).
- **Config:** `beam.ux.content_path` (env `BEAM_UX_CONTENT_PATH`, default `resources/js/content`) — the
  base-path-relative content root. Bound as a singleton by `BeamUxServiceProvider` against that config.
