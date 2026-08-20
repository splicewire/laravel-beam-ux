# splicewire/laravel-beam-ux

The beam-ux authoring package (`Splicewire\Beam\Ux\*`) — the beam-tier authoring/orchestration UX layer over beam-core.

## Serving entries publicly (`Route::beamUxSite()`)

ADR-0165 made containment the organization spine — `realm` + `parent_id` + `segment` compose an entry's
public URL. ADR-0209 supplies the other half: a resolver that walks a request path back to an entry, and
a renderer that serves it.

Mount it as the **last line** of your `web.php`:

```php
// routes/web.php — after every route your app claims for itself.
Route::beamUxSite('site/entry');
```

`'site/entry'` is **your** Inertia page component. No beam package ships a rendered page, so the name is
a mount argument with no default. The macro registers two routes:

| Route | Name | What it is |
| --- | --- | --- |
| `GET {path}` | `beam.ux.site.show` | the page shell — resolves the path, gates it, renders your component |
| `GET beam/ux/artifacts/{entry}` | `beam.ux.site.artifact` | the compiled body, as an ES module |

The catch-all goes last because Laravel matches in registration order — a package that auto-registered
it would silently claim every unmatched URL in your app. Not calling the macro is the off switch: beam-ux
stays headless-installable and gains no public surface.

```php
Route::beamUxSite(
    page: 'site/entry',
    realm: 'site',   // the only shipped case; guarded realms are Frame's SPA, not this
    claimRoot: false, // installing beam-ux must never take your homepage
    withNav: true,    // ship the gated NavTree in props, for <SiteNav rootPath>
);
```

**Middleware is yours.** A private site mounts the macro inside its own `auth` group and every entry
inherits it. Per-entry gating is ADR-0212's two rights on the row.

**Props** are `entry` (id, slug, title, type, format, url), `artifact` (url, version), and `nav`.
Import `artifact.url` from your component to render the body.

### Compile on save

Entry bodies compile to an ES module when they **change**, never when they are read, and there is no
client-compile fallback — a page with no artifact 404s and `splicewire:beam:doctor` names it. Three
producers invoke one action: the editor save, `register-from-disk`, and the backfill:

```
php artisan splicewire:beam:ux:compile [--realm=site] [--slug=…] [--force]
```

The default compiler shells out to Node against **your** `node_modules` (`@mdx-js/mdx`, `esbuild`). A
host with its own build service binds `Splicewire\Beam\Ux\Compile\EntryBodyCompiler` and keeps
everything above it.

### The docs surface, out of the box

`splicewire:beam:seed` provisions the `site` realm root and, under `beam.ux.docs.seed`, a `/docs`
subtree with the API reference page. Every installed contributor (e.g. `laravel-beam-mcp`) seeds its own
page beneath it. Customise what a fresh install seeds with
`vendor:publish --tag=beam-ux-docs`; **re-root** an existing one by editing the docs entry's `segment`
— the row is yours from the moment it exists, and re-seeding never clobbers it.

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
