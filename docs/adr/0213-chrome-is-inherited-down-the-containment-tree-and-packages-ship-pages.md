# ADR-0213 — Chrome is inherited down the containment tree, and a beam package ships pages

Status: **accepted** — beam-docs-satellite ticket 19, 2026-08-22.
Date: 2026-08-22
Builds on: **ADR-0165** (content organization is containment in a realm-rooted sitemap tree),
**ADR-0209** (the public entry renderer), **ADR-0210** (a docs-surface contribution is a seed row, an
endpoint, and a generic component), **ADR-0212** (entry access is two conjunctive rights on the row),
**ADR-0099** (the mainframe host/mode axis), **ADR-0092** (the vendor seam).
Amends: **ADR-0209 §6** — the *"no beam package ships a rendered page"* prohibition is **withdrawn**
and replaced by the two invariants it was standing in for (§2 below).
Coins: **layout** (chrome wrapping one hole) · **template** (slot-bearing scaffold the body fills) ·
**host** (persistent state above the page) · **page map** (a package's exported Inertia-page
contribution).

## Context

ADR-0209 scoped the renderer thin on composition: **one** host-supplied Inertia page name, `page` the
sole routable type, `layout` and `template` shipped as `UxType` cases with no column referencing them
and no consumer anywhere. That was correct for a docs-only renderer and stopped being correct the
moment a second surface converted.

Three pressures arrived together.

**The host copies multiplied.** `resources/js/pages/site/entry.tsx` exists five times — 84 lines in
each of the three starters (byte-identical, propagated by merge at ticket 13) and independently grown
to **262** lines on `splicewire/www` and **285** in `splicewire-app`. `layouts/beam-ux/mainframe-host.tsx`
is a sixth and seventh copy of the same shape. Every host also hand-writes an `app.tsx`
`layout: (name) => switch`, and `www`'s has already reinvented containment inheritance as a string
test: `page.url.startsWith('/beam/') ? [OsLayout, BeamLayout, MainframeHost] : [OsLayout, SiteLayout,
MainframeHost]`. This is ticket 11's rule — *a fix written at the host is a fix the next host will
need again* — with five instances and no mechanism to stop the sixth.

**The chrome decision was already being made, in CSS.** `site/entry.tsx` frames a body with
`[&>*]:mx-auto [&>*]:max-w-3xl` plus a `data-beam-full-bleed` per-child opt-out, so the API reference
spans the viewport and a guide sits in a reading measure. That *is* a template selection, expressed as
a stylesheet because there was nowhere to put it.

**Converting the app's guides forced it.** beam-docs-satellite ticket 18 (`using`/`build` → entries)
cannot execute without deciding what shell a converted guide renders in: the entry page deliberately
has no rail and no on-this-page column (they belong to the reference surfaces' full-bleed shape,
ticket 10), and `content/show.tsx` — which mounts the read⇄window authoring host — is deleted by the
same conversion. 18 was re-blocked on this ADR rather than guessing.

## Decision

### 1. Three concepts, and `shell` is not one of them

`Shell` was in use for both `DocsShell` (header + rail + main + TOC) and, inside `UxType::Layout`'s own
docblock, for what a layout is (*"a chrome shell"*). It is a synonym and it is retired as a domain
term. Three concepts remain, distinguished by **what fills them**:

| Concept | What it is | What fills it | Lifetime |
| --- | --- | --- | --- |
| **Host** | Providers, capabilities, mode state, the slot registry | Nothing — it is not visual | **Persistent** across navigations |
| **Layout** | Chrome wrapping a rendering: header, breadcrumb, rail, on-this-page | One hole | Per page |
| **Template** | A slot-bearing scaffold | The page's own body | Per page |

`Host` is the mode/state axis (ADR-0099) and is **orthogonal** to the other two — `DocsHost` is not a
bigger `DocsLayout`, it is a different question. Layout and template are the chrome axis, and the tell
that they are genuinely two is `/docs/api`: it keeps the docs header (same **layout**) and changes only
how `main` is filled (different **template**).

So the docs area is one `DocsLayout` over two templates — `ProseTemplate` (reading measure) and
`SpreadTemplate` (full bleed) — which is the CSS hack above, promoted to data.

### 2. ADR-0209 §6 is withdrawn; two invariants replace it

§6 read *"no beam package ships a rendered page."* It was written against a real failure — three
hand-wired Blade views in `splicewire-app` duplicating what the packages now contribute — but it
prohibited the wrong noun, and it has been a recurring obstruction. Five copies of one page is what
enforcing it costs.

What §6 was actually protecting, stated directly, and what this ADR keeps:

- **(i) A package ships no palette, no fonts, and no wordmark.** Already the `@splicewire/beam-ux/site`
  contract, already tested (`Prose`'s test strips `var()` wrappers and asserts nothing colour- or
  font-shaped survives).
- **(ii) A package imports no router.** A host injects its `<Link>` through `linkComponent`.

A package may therefore ship layouts, templates, **and** the Inertia page that renders an entry. It may
not ship a look, and it may not ship a router.

### 3. A package contributes pages through an exported page map

Inertia resolves pages from a **host-local** `import.meta.glob('./pages/**/*.tsx')`. A package cannot
put a name into that glob. Of the three ways to bridge it — the host publishes the file (what produced
five copies), a Vite plugin merges package globs, or a fallback resolver — the decision is the
**fallback resolver**:

A package exports a plain `Record<string, () => Promise<{ default: ComponentType }>>`. The host's
`resolve` checks its own glob **first**, the package map **second**. Consequences, all of them wanted:

- The override mechanism is *"put a file at `pages/site/entry.tsx`"* — no publish step, no opt-out flag,
  no build-tool magic, and it is the thing hosts already do.
- A host that wants the default deletes its copy. The starter's `app.tsx` gains one line and loses a file.
- No Vite plugin, so the mechanism works identically under any bundler and under SSR.

### 4. Layout and template are inherited down the containment tree

Two new nullable **string** columns on `beam_ux_entries`: `layout` and `template`. Each resolves to a
registered component name or another entry's slug. Each is **inherited from the nearest ancestor that
declares one**, with a per-entry override — the same walk ADR-0212's rights already perform over the
same chain, so no second traversal and no second address space.

`/docs` declares `DocsLayout` once and all its descendants get it; `/docs/api` overrides only its
`template` to `SpreadTemplate`. `www`'s URL-prefix branch becomes a `layout` declared on the `/beam`
entry.

Rejected: a `type → page name` map on the `beamUxSite()` macro. It is the dispatch table ADR-0209
deferred, it lives in `web.php` where the CMS cannot reach it, and `layout`/`template` are already
`UxType` values — i.e. already data. `beamUxSite('site/entry')` keeps **one** page name; the layout and
template are resolved from the entry's inherited data against a component registry the packages
populate. Also rejected: a JSON `aspects` blob. One named column per inherited aspect is the precedent
`access`/`traverse` set.

### 5. There are no bodyless entries except structural ones

A package does **not** contribute a screen by pointing a column at a component. It seeds a row whose
**body invokes the component** — `/docs/api`'s body is `<ApiReference />`, `/docs/mcp`'s is
`<ManifestTable />` — which is ADR-0210 §5's contribution contract, already built and proven at tickets
06/07. There is therefore no `component_ref` column and no "pointer entry" category.

The body says *what*; the `template` column says *how it is framed*. One column, three fills: a guide's
compiled prose in `ProseTemplate`, `<ApiReference/>` in `SpreadTemplate`, a future `settings/profile`
screen component in its own template.

The **only** bodyless rows are structural: a realm root, and any pass-through node. That category has
already produced three separate defects (`NavProjector` returning an empty tree on every correctly
seeded host, `beam:ux:compile` reporting `failed 4` forever, `BeamUxArtifactAudit` blocking on every
correct install), each patched locally. Naming it here is what stops the fourth.

### 6. A screen fetches its own data; page props are the escape hatch

A compiled artifact is a static ES module addressed by body hash — there is nowhere in it for a
per-request value. So a screen component **owns its data logic and fetches**, exactly as
`<ManifestTable>` already does with react-query. Inertia props are an optimization, not a requirement.

As an escape hatch where a round-trip is silly (the current user), the renderer passes the Inertia page
props through to the body under one well-known name.

Rejected: a `props_ref` on the entry naming a server-side resolver. It reinvents controllers as data,
and it would have to be gated, versioned, and invalidated alongside the artifact.

### 7. Composition happens at render; artifacts stay per-body

If a page's artifact were compiled *together* with its layout's, a layout edit would invalidate every
descendant's artifact — the transitive invalidation that got `resolved_path` deferred at ADR-0209.

So nothing is ever compiled together. A layout may be **registered code** (shipped by a package, no
row, no artifact) or an **authored entry** whose body compiles to its own artifact exactly like a
page's. The client imports the page artifact and its layout artifact and nests them. Resolution order:
registered name first, then entry.

### 8. Nav grouping is `nav_group`, the word the other registries already use

Grouping was built twice and never on the containment tree: `@schemastud/nav`'s `NavNode` carries
`track`/`group`/`order`/`groupOrder`, and `Splicewire\Beam\Realm\RealmResourceOverride` carries
`group`, `section`, `navOrder` — and `layout`. The entry tree is the one place that has none of them.

Add **`nav_group`** (nullable string). `NavProjector` emits one href-less `NavLink` per distinct
`nav_group` among a parent's children, ordered by `nav_order`; ungrouped children stay at the parent's
level. `NavLink::make()`'s `href` is already nullable, so the schema needs nothing.

Rejected: **making a group a URL segment** (`/docs/build/concepts/agents`). It moves every guide URL,
requires a 301 map where ticket 18 expected the legacy block to collapse, and gives each group node a
landing body nobody will write. Rejected: a `nav_role` enum distinguishing *heading* from
*pass-through* on segment-less rows. `nav_group` needs no third state, so the realm root stays a plain
pass-through.

`track` needs nothing: on a containment tree the track **is** the parent.

## Consequences

- Five copies of `site/entry.tsx` (84 / 84 / 84 / 262 / 285 lines) collapse toward one, plus a host
  override where a host genuinely differs. Same for `mainframe-host.tsx`.
- `beam_ux_entries` gains three nullable columns: `layout`, `template`, `nav_group`.
- `@splicewire/beam-ux` gains a `/docs` entry point — `DocsHost` and the docs layout/templates — kept
  out of `/site`, whose stated contract is no router and no state, because the host is all providers
  and mode state and needs `@schemastud/mainframe`.
- The fog item *"the in-place editor and the entry renderer do not know about each other"* is fixed
  here: `MainframeHost` derives its slug from the Inertia **component name** and so probes a
  nonexistent `site-entry` row on every rendered entry. The renderer already has the entry id in its
  props; the host reads it off the payload.
- ADR-0209 §6 must be edited in place to record the withdrawal — a package author reading only 0209
  must not find the prohibition still stated.
- **Not decided here, deliberately:** `RealmResourceOverride` (`group`/`section`/`navOrder`/`layout`,
  per realm, over `ResourceDefinition`) is a second registry saying the same things as the containment
  tree, for the `account`/`settings`/`operator` surfaces. Collapsing the two is right and is a
  fleet-wide re-platforming of every beam host's screen registry — past beam-docs-satellite's
  destination. This ADR **borrows its field names** (`layout`, `group`) precisely so that merge is a
  rename rather than a translation.
