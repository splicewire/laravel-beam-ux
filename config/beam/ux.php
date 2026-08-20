<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entry-body authoring API root (ADR-0124 owner-tier seam)
    |--------------------------------------------------------------------------
    |
    | The owner-tier URI prefix + route-name stem the HOST mounts the entry-body
    | authoring endpoints at via `Route::beamUxEntries()`, resolved client-side by
    | the `beam.ux.entries.body.*` route names. beam-ux is a `Splicewire\Beam\*`
    | package → the free `/beam` tier, domain `ux`, so the defaults are `beam/ux`
    | (→ `GET|PUT beam/ux/entries/{slug}/body`) and the `beam.ux.` name stem.
    |
    | Config-driven (not hardcoded in the macro) so a host can relocate the mount
    | per-deploy via env — or by passing explicit args to the macro — without a
    | code change. The package ships the default + the (policy-free) controller;
    | the host owns the mount, the auth middleware (which IS the write gate), and
    | the wire.
    |
    */
    'api_root' => env('BEAM_UX_API_ROOT', 'beam/ux'),

    'route_name' => env('BEAM_UX_ROUTE_NAME', 'beam.ux.'),

    /*
    |--------------------------------------------------------------------------
    | Default disk-grouping namespace (ADR-0165 — NOT the URL)
    |--------------------------------------------------------------------------
    |
    | The default `namespace` the authoring/seed commands
    | (`ux-scaffold` / `ux-seed-nav` / `ux-enrich-page-schemas`) group entries
    | under when no explicit `--namespace` is passed. `namespace` is the
    | disk-grouping coordinate ONLY (ADR-0165 two-trees) — it derives the disk
    | *path* (`{namespace}/{type}/{slug}.{ext}`), never the URL, and is unrelated
    | to multi-tenancy. In a single-tenant install it is arbitrary-but-consistent;
    | set it once here (or via env) instead of hard-coding it per command.
    |
    | Default `''` — an empty namespace files entries at the type root
    | (`{type}/{slug}.{ext}`). A host that groups on disk sets its own slug.
    |
    */
    'namespace' => env('BEAM_UX_NAMESPACE', ''),

    /*
    |--------------------------------------------------------------------------
    | Content nav source (ux-seed-nav)
    |--------------------------------------------------------------------------
    |
    | The data `splicewire:beam:ux:seed-nav` seeds the sitemap content nav from.
    | Resolution priority (highest first):
    |
    |   1. `beam.ux.nav` (this key)     — an explicit override; a list of nav
    |      rows `[slug, segment, title, type, realm]` (or the assoc-map form).
    |      Highest so a host can pin the nav exactly.
    |   2. `resources/beam-ux/nav.{yml,json}` on disk — an authored nav file, if
    |      present (relative to the mirror-disk root, else base_path()).
    |   3. DERIVED from registered entries' frontmatter (`segment`/`realm`/
    |      `nav_order`) — the default when neither above is set. A fresh site
    |      seeds its nav with NO bespoke PHP: the frontmatter carries it.
    |
    | Null (default) ⇒ fall through to disk, then to frontmatter derivation.
    |
    */
    'nav' => null,

    /*
    |--------------------------------------------------------------------------
    | Seed the content nav via splicewire:beam:seed
    |--------------------------------------------------------------------------
    |
    | The config GATE for beam-ux's NavSeeder registration in beam-core's
    | package-registered seed manifest (`splicewire:beam:seed`). The NavSeeder
    | is a thin `db:seed --class` adapter over `splicewire:beam:ux:seed-nav`, so
    | a host's one `beam:seed` run restamps the per-realm sitemaps' nav after a
    | migrate:fresh — no bespoke DatabaseSeeder call.
    |
    | Default true. Set false to keep nav-seeding out of the aggregate run (a
    | host that seeds nav on its own schedule); the `splicewire:beam:ux:seed-nav`
    | command stays independently callable either way.
    |
    */
    'seed_nav' => env('BEAM_UX_SEED_NAV', true),

    /*
    |--------------------------------------------------------------------------
    | Raw-MDX content root (RawMdxReader)
    |--------------------------------------------------------------------------
    |
    | The base-path-relative root the `RawMdxReader` reads disk-authored `.mdx`
    | content files from, to seed an mdxeditor buffer with the EXISTING copy.
    | The vite `@mdx-js` plugin compiles every `.mdx` (regardless of `?raw`), so
    | the client can't obtain the original source — the read happens server-side.
    |
    | Default `resources/js/content` — a Vite-served content dir alongside the
    | app. A host that authors its MDX elsewhere overrides via env; a `{name}.mdx`
    | that is absent degrades to `null` (the caller renders its default).
    |
    */
    'content_path' => env('BEAM_UX_CONTENT_PATH', 'resources/js/content'),

    /*
    |--------------------------------------------------------------------------
    | Realm conventions (register-from-disk path → realm fallback)
    |--------------------------------------------------------------------------
    |
    | When a page file declares no `realm:` in its frontmatter, `register-from-disk`
    | maps its DISK PATH to a realm through this ordered `glob => realm` map
    | (fnmatch against the disk-relative path — scope by segment, e.g.
    | `*​/page/library-*`). First match wins; no match ⇒ the model's `site`
    | default. This is a realm-only fallback: `segment` (the URL) is still declared
    | in frontmatter, never guessed. Frontmatter `realm:` always outranks this.
    |
    | Default `[]` ⇒ no convention (pure frontmatter). A host groups its realms by
    | path here so a fresh page lands in the right realm with zero per-file `realm:`:
    |
    |   'realm_conventions' => [
    |       '*​/page/library-*'  => 'account',
    |       '*​/page/operator-*' => 'operator',
    |       '*​/page/auth-*'     => 'auth',
    |   ],
    |
    */
    'realm_conventions' => [],

    /*
    |--------------------------------------------------------------------------
    | Storage (ADR-0165 S2 — the disk seam)
    |--------------------------------------------------------------------------
    |
    | `disk`         — the filesystem disk the DEFAULT Stacked(Particle, Disk)
    |                  driver mirrors to, keyed by particle id. Null ⇒ the
    |                  framework default disk.
    | `mirror_disk`  — the filesystem disk the placement-keyed PlacedDiskMirror
    |                  projects to on Publish, keyed by the FilePlacement
    |                  path (`{namespace}/{type}/{slug}.{ext}`). This is the
    |                  human/git-facing projection — point it at a git-tracked
    |                  dev dir to version-control entry bodies as source files.
    |                  Null/unset ⇒ the mirror is a no-op (degrade-not-fabricate).
    | `namespaces`   — namespace-prefix → driver-name map for the resolver.
    |
    */
    'storage' => [
        'disk' => env('BEAM_UX_STORAGE_DISK'),
        'mirror_disk' => env('BEAM_UX_STORAGE_MIRROR_DISK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access (ADR-0212 — the two conjunctive rights)
    |--------------------------------------------------------------------------
    |
    | The `traverse`/`access` token lists on an entry are OPAQUE to beam-ux
    | (ADR-0092 — host RBAC vocabulary stays host-side); the bound
    | `EntryAccessGate` evaluates them. These two keys are the host-specific
    | values the default `TokenAccessGate` carried down from tower's AccessGate
    | as hardcoded constants.
    |
    | `root_role`     — the role the reserved `root` token resolves against.
    |                   With no RBAC package present the token simply denies.
    | `extra_tokens`  — host tokens `knows()` should recognise beyond `root`,
    |                   `auth`, and ADR-0118's `alias.verb` permission shape.
    |                   `knows()` is what makes a typo'd token a loud import
    |                   error instead of a silent lockout, so widen this list
    |                   rather than loosening validation.
    |
    */
    'access' => [
        'root_role' => env('BEAM_UX_ACCESS_ROOT_ROLE', 'Root'),
        'extra_tokens' => [],
    ],

];
