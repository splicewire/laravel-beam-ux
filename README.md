# splicewire/laravel-beam-ux

The beam-ux authoring package (`Splicewire\Beam\Ux\*`) — the beam-tier authoring/orchestration UX layer over beam-core.

## Mounting a second realm's console (`Route::beamRealmConsole()`)

The tenant console is the host's root mount. A realm with its own route base (`operator` at `/operator`, a
package realm such as `exec` at `/exec`) gets its console from one line, above `Route::beamUxSite()`:

```php
Route::beamRealmConsole('exec', ['auth', 'verified']);
```

| route | path | what |
|---|---|---|
| `{realm}.home` | `{base}` | redirects to `{base}/dashboard`, the realm's dashboard leaf |
| `{realm}.frame.manifest` | `{base}/frame/manifest` | `FrameManifestController` with `defaults('realm', $realm)` |
| `{realm}.frame.console` | `{base}/{frameRoute}` | the `frame/console` page with `{realm, basename, manifestUrl}` |

- The middleware is yours, plus `can:{ability}` for the realm's own gate (`RealmGateAbility::for()`), so the
  console is never a softer door than its dashboard. A realm that gates nothing gets no `can:`.
- `{frameRoute}` is constrained to the first segments of every leaf the realm projects and every nav seat
  declared for it. Nothing is written down; a projector failure mounts no console.
- `page:` names another page component (default `frame/console`); `home: false` leaves the front door to you.
- The page renders through the `EntryRenderer` port (Inertia by default). A realm mounted at `/` is refused.

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

### Fenced code highlighting

MDX fenced code with a language uses Shiki at compile time. The worker resolves it from the
host first, then from `@splicewire/beam-mdx` (which declares it). Missing Shiki or an invalid
highlight configuration reports `highlight-unavailable` and keeps plain code; publishing still
works. Unknown languages use plain text. Inline code is unchanged.

Set `beam.ux.compile.highlight.themes` to light/dark Shiki theme names (defaults `github-light`
and `github-dark`). Set `langs` to a language/alias allowlist; `null` permits bundled languages,
while `[]` keeps all fenced code plain. Token spans carry both `--shiki-light` and
`--shiki-dark`; beam-mdx's prose/kit CSS selects the host's current theme. Highlighting does not
run in the browser. See [Shiki's dual-theme options](https://shiki.style/guide/dual-themes).

Generation 5 requires recompiling previously stored artifacts through the owning host's normal
compile command; rollout and per-host `compile --missing` belong to the launch GO.

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

## Registries

This package declares three registries. The [beam-ux cluster page](docs/registries/beam.ux.mdx) says which one you
reach for.

- [`beam.ux.codecs`](docs/registries/beam.ux.codecs.mdx): one codec per source format, translating an entry's raw
  TSX, MDX or CSS into its stored body and back.
- [`beam.ux.placements`](docs/registries/beam.ux.placements.mdx): the strategies that derive the disk path an entry's
  file is mirrored to.
- [`beam.ux.storage-drivers`](docs/registries/beam.ux.storage-drivers.mdx): the drivers that decide where an entry's
  body is read and written.

It registers into registries `splicewire/laravel-beam` owns:

- [`beam.install.steps`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.install.steps.mdx):
  the beam-ux install step.
- [`beam.doctor.audits`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.doctor.audits.mdx):
  the beam-ux audits, covering migrations, access, chrome, MDX pages, themes, artifacts, routes, redirects,
  reachability and the version store, among others.
- [`beam.seed.steps`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.seed.steps.mdx):
  the beam-ux seeder.
- [`beam.nav.sections`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.nav.sections.mdx):
  the `authoring` and `ops` sections in the `operator` and `tenant` realms.
- [`beam.particle.resources`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.particle.resources.mdx)
  and [`beam.particle.operations`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.particle.operations.mdx):
  the entry, release, redirect, mirror-status and health resources and their operations, plus a `{realm}-dashboard`
  resource for each realm that has none.
- [`schemas.sources`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/schemas.sources.mdx):
  the `theme` schema source.

It also registers into registries other packages own:

- [`beam.analytics.areas`](https://github.com/splicewire/laravel-beam-analytics/blob/main/docs/registries/beam.analytics.areas.mdx),
  owned by `splicewire/laravel-beam-analytics`: the **Site** area, `analytics-site`. It counts published pages, indexed
  pages and published pages the sitemap leaves out from Sitemap health, the Redirects, and in Install scope the mirror
  state from Site health, each figure the count its own list reports to the same reader and linking to that list where
  this realm mounts it. Its gate is `analytics.view` and the diagnostics read ability (`beam-ux.diagnostics.view`).
- [`beam.sitemap.sources`](https://github.com/splicewire/laravel-beam-sitemap/blob/main/docs/registries/beam.sitemap.sources.mdx),
  owned by `splicewire/laravel-beam-sitemap`: `EntrySitemapSource`, for published entries.
- [`beam.workflows.blueprints`](https://github.com/splicewire/laravel-beam-workflows/blob/main/docs/registries/beam.workflows.blueprints.mdx),
  [`beam.workflows.bindings`](https://github.com/splicewire/laravel-beam-workflows/blob/main/docs/registries/beam.workflows.bindings.mdx),
  [`beam.workflows.subject-resolvers`](https://github.com/splicewire/laravel-beam-workflows/blob/main/docs/registries/beam.workflows.subject-resolvers.mdx)
  and [`beam.workflows.types`](https://github.com/splicewire/laravel-beam-workflows/blob/main/docs/registries/beam.workflows.types.mdx),
  owned by `splicewire/laravel-beam-workflows`: the entry publish and release lifecycles, the release binding and
  resolver, and the `page` and `component` types. Only when that package is installed.
- [`data-nav`](https://github.com/stephenr85/laravel-data-nav/blob/main/docs/registries/data-nav.mdx) and
  [`data-nav.navigations`](https://github.com/stephenr85/laravel-data-nav/blob/main/docs/registries/data-nav.navigations.mdx),
  owned by `rushing/laravel-data-nav`: the frame-resources nav invocable, and one declared-section navigation per realm.
