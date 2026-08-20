# ADR-0210 — A docs-surface contribution is a seed row, an endpoint, and a generic component

Status: accepted — beam-docs-satellite ticket 04 (`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/laravel-beam/beam-docs-satellite/tickets/04-docs-surface-contribution-registry.md`).
Builds on ADR-0209 (the public entry renderer) and ADR-0165 (containment is the org spine). Composes
ADR-0164 (the format axis), ADR-0116 (host owns the wire), ADR-0145 (advertised catalog vs. effective
manifest, `splicewire/tower`).
Date: 2026-08-19

## Context

The beam-docs-satellite effort needs `splicewire/laravel-beam-mcp` to contribute an MCP reference page
to a beam site's docs without shipping a rendered page and without depending on `laravel-beam-ux` —
an MCP server with no UX package is a legitimate install. The charted assumption was that this
requires a **docs-surface registry**: contributors register a title/slug/data-provider, and the docs
shell asks at render time "who has a reference surface to contribute?"

Two prior rulings had already eroded that assumption without anyone noticing:

- **ADR-0165 / ticket 02** made docs pages `BeamUxEntry` rows under the `site` realm, so *at render
  time the entries table already is the index of what exists*, and `Containment/NavProjector` already
  projects it to navigation.
- **ADR-0209 / ticket 14** made a URL→entry reverse walk serve those rows, so page discovery is a
  query, not a registry read.

What remained was **install-time discovery** — "which installed packages have a docs page to seed?" —
and the estate already has a manifest for exactly that shape.

## Decision

**There is no new registry. A contribution is three separable things: a seed row, an endpoint, and a
generic component.**

### 1. `BeamSeedManifest` is the registry

`Splicewire\Beam\Seed\BeamSeedManifest` is a container singleton every beam-* package pushes a
`SeedStep` into from its own provider, with core-first ordering, per-package idempotence, and an
optional `configGate`. Its docblock already states this ADR's required invariant verbatim: *"consumers
register DOWN into beam's manifest; beam-core never learns a consumer's name."* A contributing package
registers a seed step whose seeder inserts its page entry.

Rejected: a new `ReferenceSurfaceRegistry` in beam core or beam-ux. Alongside the existing install /
seed / doctor manifests it would be a fourth self-registration manifest doing what the third already
does — a dispatch table with one live row.

### 2. Format and freshness are orthogonal axes

A contributed page is an ordinary `page` entry. Its **format** (`mdx` / `tsx`) is an authorship
choice: `UxFormat::extension()` drives disk placement, so both mirror to disk identically, and
`CodecRegistry` already binds `TsxBodyCodec` and `MdxBodyCodec` side by side. Its **freshness** is
supplied by an embedded component, available in either format — `MDX_COMPONENTS` proves MDX bodies
already take a component map.

There is therefore **no "guides are mdx, reference surfaces are components" rule**. A guide may be tsx;
a reference page may be mdx prose wrapping a live component.

### 3. Live data comes from a contributor-mounted JSON endpoint

The contributing package auto-registers a fixed, namespaced, read-only JSON route (e.g.
`GET /beam/mcp/manifest.json`). This is not the concern ADR-0116 guards against — that is a package
silently claiming *unmatched* URLs, which is what made the ADR-0209 renderer host-mounted. Requiring a
host to hand-mount one route per installed contributor would defeat the install-and-it-appears
property this whole shape exists for.

Rejected: **one endpoint that fans out to registered providers.** It reintroduces the render-time
registry §1 deletes, and it couples contributors' cache lifetimes, failure modes, and auth — whereas
§6 requires each to degrade independently.

Rejected: **pushing payloads into `Splicewire\Beam\Manifest\ManifestIndex`.** That is the estate's
"index of indexes" — a catalogue of *injection seams* (`ManifestSeam`, `registerHint`, `where`) read by
the `splicewire:beam:manifests` console command, never served over HTTP. `beam-mcp` already describes
itself into it; that satisfies developer discoverability and is the wrong vehicle for runtime data.

### 4. The endpoint serves the advertised catalog; per-caller narrowing is a seam

Per ADR-0145, `Splicewire\Tower\Mcp\Manifest\AdvertisedCatalog` names itself *"the marketing surface
behind `/docs/mcp`"*, and draws the line as *"you advertise what the vendor OFFERS; you resolve what a
caller may CALL."*

The narrowed alternative is unavailable to a beam package regardless: `EffectiveManifestResolver`
imports `Splicewire\Beam\Commerce\Entitlements\EntitlementGate` and `Splicewire\Beam\Tenancy\Tenant`,
so per-caller resolution would make `beam-mcp` require the commerce and tenancy stacks to render a docs
page — a worse violation of headless-installability than shipping a view.

An **optional per-caller availability overlay** is left as a seam: a host that has a resolver may
supply marks indicating which advertised entries this caller actually has, and the component renders
them when present and ignores them when absent. This is strictly better than swapping the list — a
reader learns the tool exists *and* that it is gated — and it keeps the page body publicly cacheable,
avoiding the stale-authorization window ADR-0122 and ADR-0209 §8 close with `no-store`.

### 5. Components live in `@splicewire/beam-ux/site`, and are generic

`@splicewire/beam-mdx/kit` is a **prose kit** — `callout`, `steps`, `figure`, `file-tree`, `terminal`,
`section-landing`, `doctor-output` — none of which fetch anything. A data-fetching component there
would be the first of its kind and would be trapped behind a format, unusable from a tsx page.

`@splicewire/beam-ux/site` is already *"generic public-site chrome … so a fresh beam host gets
marketing + legal-page chrome OOTB"*, already consumes `NavProjector`'s output, and already ships no
palette, fonts, or router. It also mirrors the PHP-side ownership: beam-ux owns the docs surface on
both sides of the wire. The line is **prose primitive → `beam-mdx/kit`; live site surface →
`beam-ux/site`**, and it is format-independent by construction.

This shelf gains:

- **`<ManifestTable endpoint="…" />`** — renders a declared `{name, title, description}` shape from a
  URL. Generic, so a contributing PHP package ships **zero frontend**.
- **`<ApiReference specUrl="…" />`** — wraps the spec renderer. Named for its **role**, not its vendor,
  matching `SiteLayout` / `SiteNav`: swapping Scalar for another renderer becomes an edit inside one
  component instead of an edit to every page body. (Named cost: the current implementation loads Scalar
  from a CDN at runtime, which an air-gapped or CSP-strict install cannot do.)
- **`rootPath` and `maxDepth` on `<SiteNav>`** — rather than a near-duplicate `<DocsNav>`. `NavProjector`
  already returns a full recursive `NavTree` and `SiteNavItem` already carries `children` (annotated
  *"Unused by the flat `<SiteNav>`"*), so the tree data exists today and only the renderer is flat.
  `rootPath` selects a subtree of the already-delivered projection client-side, so the docs shell gets
  the site nav and the docs sidebar from one payload. Cost: a nested render must emit `<ul>`/`<li>`
  where today it emits a bare fragment — within the posture `SiteLayout` already sets.

Consequence: `MDX_COMPONENTS`-style maps need no codegen. Contributors never ship components, so the
map has no dynamic membership — it is the kit's exports plus whatever a host adds for itself.

### 6. Absence degrades loudly in both directions

- **Contributor removed, page remains.** The seed row is site-owned from creation (ticket 02 §4) and
  survives. `<ManifestTable>` renders an inline "not installed" empty state and the page still 200s;
  `beam:ux:doctor` reports the orphan, following ADR-0209 §7's precedent of doctor as the reporting
  seam for entries in a broken state. Silently 404ing a page the site owns would repeat the
  invisible-failure class ADR-0209 rejected.
- **Contributor installed, no beam-ux.** The seed step registers unconditionally — `BeamSeedManifest`
  takes a class-string, so nothing loads until the seeder runs — and the seeder no-ops when
  `BeamUxEntry` is unresolvable, so a headless host can run `splicewire:beam:seed` and get a reported
  skip rather than a crash. This follows `BeamMarketServiceProvider`, which depends on beam-ux traits
  while deliberately not force-booting `BeamUxServiceProvider`. The JSON endpoint still mounts; a
  headless MCP host serving its own manifest is harmless and arguably useful.

### 7. `laravel-beam-mdx` becomes a codec/engine only

ADR-0209 deprecated `beamMdxShow`/`beamMdxPage` and killed the build-time MDX bundle, which removes
most of what the ux↔mdx line was ever about. What remains — `Docs/Regenerate/*` and the docs-specific
doctor — is docs knowledge inside a package named for a **format**. It is audited and moved or deleted
during extraction. After that, `beam-mdx` means the format and nothing else.

## Consequences

- The MCP reference page becomes: one seed row (beam-mcp), one JSON route (beam-mcp), one generic
  component (beam-ux/site). No beam package ships a rendered page; `beam-mcp` ships no frontend at all
  and gains no dependency on beam-ux, beam-commerce, or beam-tenancy.
- The API reference page is seeded by **beam-ux**, not core: beam core requires no splicewire package
  and must not grow a conditional dependency on one to seed a page about itself. It rides beam-ux's
  published docs stub alongside the docs root. `beam-mcp` is the genuinely optional contributor and the
  one that needs the conditional pattern of §6.
- A site can swap or restyle any contributed page by editing one entry, because the row is data it owns.
- The advertised-catalog ruling is scoped to **docs**. It deliberately does not pre-answer the OpenAPI
  spec-variance question, which is a harder problem.
