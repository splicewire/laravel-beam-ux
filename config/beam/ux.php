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
    | Public site renderer (ADR-0209 — the host-mounted mount)
    |--------------------------------------------------------------------------
    |
    | The renderer itself is mounted by the HOST (`Route::beamUxSite($page)`) as
    | the last line of its `web.php` — a package that silently claims every
    | unmatched URL in an application is a day of debugging, and only the host
    | can guarantee the registration ordering that makes a catch-all safe. So
    | there is deliberately no `enabled` key here: not calling the macro IS the
    | off switch, and a host that never calls it has no public surface at all.
    |
    | `artifact_root` — the URI prefix the compiled-body stream mounts under
    |                   (`GET {artifact_root}/{entry}`). Registered BEFORE the
    |                   catch-all by the macro, so the catch-all cannot swallow
    |                   it. Relocatable per-deploy like the authoring root above.
    |
    | `reserved_prefixes` — URI prefixes the catch-all refuses to match, as a
    |                   route CONSTRAINT rather than a controller check: Laravel
    |                   has no "next route", so a catch-all that resolves and
    |                   then aborts has already swallowed the URL (ADR-0209 §2).
    |
    |                   The default reserves `api` because "last line of web.php"
    |                   does not mean "registered last" — routes mounted from a
    |                   `booted()` callback (stancl/tenancy's `routes/tenant.php`)
    |                   or from later in the host's own route closure register
    |                   AFTER every line of `web.php`, and lose to this route by
    |                   construction. `api` is also already beam's fleet-wide API
    |                   boundary (ADR-0211 §7), so reserving it here invents no
    |                   new convention.
    |
    |                   A host adds its own prefixes here (or passes them to the
    |                   macro); a host served wholly from entries with no API at
    |                   all may set it to `[]`. Matching is anchored and
    |                   segment-aware: `api` reserves `/api` and `/api/...` and
    |                   nothing else — an entry at `/docs/api` is untouched.
    |
    */
    'site' => [
        'artifact_root' => env('BEAM_UX_ARTIFACT_ROOT', 'beam/ux/artifacts'),
        'reserved_prefixes' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compile on save (ADR-0209 §7)
    |--------------------------------------------------------------------------
    |
    | Entry bodies are compiled to an ES module when they CHANGE, never when they
    | are read, and there is NO silent client-compile fallback — degrading to
    | shipping an MDX compiler to the browser is an invisible regression that
    | surfaces months later in someone's performance audit. A page with no
    | artifact 404s and `beam:doctor` names it.
    |
    | `disk`     — the filesystem disk artifacts are written to. Null ⇒ the
    |              framework default disk. Artifacts are addressed by entry id +
    |              particle version, so a stale one is a DIFFERENT address rather
    |              than an out-of-date file: there is no invalidation to get wrong.
    | `root`     — the directory prefix on that disk.
    | `binary`   — the Node binary the default compiler shells out to. Dependencies
    |              (`@mdx-js/mdx`, `esbuild`) resolve from the HOST's node_modules;
    |              beam-ux vendors no toolchain.
    | `script`   — override the compile script (a host with a warm build service or
    |              a bespoke pipeline). Null ⇒ the package's own `resources/compile`.
    | `timeout`  — seconds before one compile is abandoned.
    |
    | A host that wants none of this binds its own `Compile\EntryBodyCompiler`;
    | everything above that port (the shared action, the backfill command, the
    | doctor check) is unchanged by the swap.
    |
    */
    'compile' => [
        'disk' => env('BEAM_UX_COMPILE_DISK'),
        'root' => env('BEAM_UX_COMPILE_ROOT', 'beam-ux/artifacts'),
        'binary' => env('BEAM_UX_NODE_BINARY', 'node'),
        'script' => env('BEAM_UX_COMPILE_SCRIPT'),
        'timeout' => env('BEAM_UX_COMPILE_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs subtree seed (ADR-0210 — the OTB docs surface)
    |--------------------------------------------------------------------------
    |
    | `splicewire:beam:seed` seeds the `site` realm root (ADR-0209 §9 — the
    | renderer never writes, so SOMETHING has to make the root exist) and, under
    | the gate below, the docs subtree beneath it: a docs root plus the API
    | reference page beam-ux contributes.
    |
    | `segment` is the docs root's own URL segment and it is seeded as DATA the
    | site owns from creation — re-rooting to `/beam/docs` is an edit to one row,
    | not a config change and not a realm. This key is the seed's INITIAL value
    | only; it is never read again, and editing it later moves nothing.
    |
    */
    'docs' => [
        'seed' => env('BEAM_UX_SEED_DOCS', true),
        'segment' => env('BEAM_UX_DOCS_SEGMENT', '/docs'),
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

    /*
    |--------------------------------------------------------------------------
    | Chrome (ADR-0213 — layout/template inherited down the containment tree)
    |--------------------------------------------------------------------------
    |
    | An entry's `layout`/`template` resolve CLIENT-side: a registered component
    | name first, then another entry's slug (ADR-0213 §7). The registry itself is
    | a TypeScript `Record` in the host's bundle, which PHP cannot see — so this
    | key is how a host TELLS the doctor what its bundle registers, and it is the
    | only reason the key exists.
    |
    | Seeded with the names `@splicewire/beam-ux/docs` ships. A host that adds its
    | own layout adds one string here; a host that adds none never touches it.
    | `BeamUxChromeAudit` fails on any declared name that is in neither this list
    | nor the entries table — a stale list is a false alarm, which is the right
    | direction, because the alternative is a guide that silently loses its rail
    | behind a 200.
    |
    */
    'chrome' => [
        'registered' => [
            'DocsLayout',
            'ProseTemplate',
            'SpreadTemplate',
        ],
    ],

];
