<?php

return [

    /*
    |--------------------------------------------------------------------------
    | beam-ux's owner-tier URI prefix + route-name stem (ADR-0124 owner-tier seam)
    |--------------------------------------------------------------------------
    |
    | beam-ux is a `Splicewire\Beam\*` package → the free `/beam` tier, domain
    | `ux`, so the defaults are `beam/ux` and the `beam.ux.` name stem.
    |
    | BOTH KEYS OUTLIVED WHAT INTRODUCED THEM. They were added for the entry-body
    | authoring mount `Route::beamUxEntries()`, which is retired (ADR-0214 §6 —
    | the transport is now two id-addressed particle OPERATIONS on the
    | `beam-ux-entry` resource, mounted by the host with `Route::particleOp()`).
    | ADR-0214 originally deleted these keys with the macro; that was amended
    | after measuring who else reads them, and neither is a mount prefix now:
    |
    |  - `api_root` is read by beam core's PUBLISHED Scribe stub
    |    (`splicewire/laravel-beam/stubs/scribe/scribe.php`) to derive the OpenAPI
    |    extraction include-list — asserted by `ScribeOutputContractAudit`, pinned
    |    by `PublishedScribeStubTest`, documented in ADR-0211. Three hosts carry
    |    the published copy. Deleting it silently drops `beam/ux/*` from the spec.
    |  - `route_name` is read by `Concerns\WiresPublicSurface` to name the
    |    public-entry ARTIFACT route, the one ADR-0209 §7 hangs its immutable
    |    cache header on. Deleting it renames that route.
    |
    | Env-overridable so a deploy can move either without a code change.
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
    |                   A host ADDS its own prefixes here (or passes them to the
    |                   macro); the three sources are unioned with the package's
    |                   `api` baseline, never replacing it, so a host that
    |                   reserves `mcp` keeps `api` reserved and `[]` means "add
    |                   nothing" (api-surface-coherence 134 / 142: a host-side
    |                   list must compose). ⚠️ Laravel's config merge is shallow —
    |                   a published host file that carries only this key is fine,
    |                   because the macro falls back to `artifact_root`'s default
    |                   on its own. Matching is anchored and segment-aware: `api`
    |                   reserves `/api` and `/api/...` and nothing else — an
    |                   entry at `/docs/api` is untouched.
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
    | behind a 200. A name that IS an entry's slug is accepted only on the terms
    | the renderer nests it (a live, compiled page); otherwise it warns.
    |
    */
    'chrome' => [
        'registered' => [
            'DocsLayout',
            'ProseTemplate',
            'SpreadTemplate',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The `nav` + `routeContext` half of /frame/manifest
    |--------------------------------------------------------------------------
    |
    | Frame's manifest has always emitted `resources` + `contexts`. `nav` and
    | `routeContext` — the resolved navigation tree and the flat router table the
    | client expands into leaves — reached the wire at EXACTLY ONE host in the
    | estate, and only because that host hand-wrote its own copy of frame's
    | manifest controller. Filling frame's `FrameNavContributor` plug from here
    | is what makes them available to any host that installs beam-ux.
    |
    |  - `enabled`        bind the contributor at all. Additive when on: with no
    |                     resolvable realm and no registered navigation, the
    |                     contributor DECLINES and the payload is unchanged.
    |  - `default_realm`  the realm to project when the matched route stamps no
    |                     `realm` default — i.e. when a host mounts frame's single
    |                     default `/frame/manifest` rather than one per realm.
    |                     `tenant` is not a package guessing a HOST fact: it is one
    |                     of the four BASE realms `Splicewire\Beam\Realm\RealmRegistry`
    |                     itself ships, so the default names beam's own vocabulary.
    |                     A host with no such realm gets `tryResolve() === null` and
    |                     the contributor declines — an absence, never a failure.
    | The HOST's own information architecture — which resource nests under which
    | shell, which gets a heavyweight editor — is NOT here. It arrives by binding
    | `Splicewire\Beam\Ux\Frame\RouteContextPlan` from the host's own provider,
    | and a config mirror of those seven lists is deliberately absent: it would be
    | a second grammar for the same facts with no host reading it.
    |
    | ⚠️ An empty plan is not the same as an unnecessary one. A resource reaches a
    | realm only through `config('frame.realms')` — a host-side list
    | (`api-surface-coherence` 142). A host that has spelled no membership gets an
    | EMPTY routeContext, which is the honest reading and not a bug in the projection.
    |
    */
    'frame_nav' => [
        'enabled' => env('BEAM_UX_FRAME_NAV', true),
        'default_realm' => env('BEAM_UX_FRAME_NAV_REALM', 'tenant'),
    ],

];
