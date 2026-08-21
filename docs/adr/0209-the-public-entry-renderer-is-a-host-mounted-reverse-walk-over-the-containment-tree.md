# ADR-0209 — The public entry renderer is a host-mounted reverse walk over the containment tree

Status: accepted — beam-docs-satellite ticket 14 (`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/laravel-beam/beam-docs-satellite/tickets/14-public-entry-renderer.md`).
Completes ADR-0165 (containment is the org spine) by supplying its missing half. Refines ADR-0122
(guarded-guide delivery). Composes ADR-0116 (host owns the wire), ADR-0156 (realms), ADR-0164 (the
format axis), ADR-0166 (the sitemap seam).
Date: 2026-08-19

## Context

ADR-0165 made containment the organization spine: `realm` + `parent_id` + `segment` compose an entry's
public URL DOWN the tree from the realm root. `Containment/UrlResolver` implements that composition —
and **has zero callers estate-wide.** `laravel-beam-ux` ships two controllers (entry body, mirror
status) and **no public routes at all**. `laravel-beam-sitemap`'s `EntrySitemapSource` proves entries
are already *enumerable*; nothing makes them *servable*.

So ADR-0165 shipped exactly half of itself. The tree can tell you an entry's URL; no code resolves a
request from one. `BeamUxEntry::rootFor()` — the realm root the whole scheme hangs from — likewise has
no production callers, only test fixtures, so no live database even has a root row.

This became blocking when the beam-docs-satellite effort ruled (ticket 02) that docs are not a realm
but a containment subtree under `site`, with the docs path a `segment` on a seeded root entry. That
ruling is unshippable without a renderer, and the renderer is a general beam-ux capability rather than
a docs feature — docs is merely its first and most honest consumer.

## Decision

**A path→entry resolver and public renderer ship in `laravel-beam-ux`, mounted by the host, serving
the `site` realm.**

### 1. Resolution is a two-phase reverse walk

`UrlResolver` composes *down*; serving needs the inverse. Resolution is a walk down from the realm
root, one indexed `(parent_id, segment)` lookup per path segment — **preceded by a direct query for a
root-absolute segment match.**

The second phase is not an optimization, it is required for correctness. ADR-0165 §5's grammar lets
any node's `segment` begin with `/`, resetting to the realm root and ignoring every ancestor. Such an
entry resolves to a shallow URL no matter how deep it sits in the tree, so a pure top-down walk can
never reach it.

Rejected: **forbidding absolute segments on routable pages.** That removes a shipped grammar feature to
suit an implementation, and re-rooting a docs subtree to `/beam/docs` by editing one row *is* an
absolute-segment edit — the exact capability the containment model was chosen to provide.

### 2. The host mounts the routes; the package auto-registers nothing

The renderer is mounted by a `Route::` macro the host calls as the last line of its `web.php`,
following `Route::beamUxEntries()` and ADR-0116's *"host owns the wire and the policy."* Laravel
matches in registration order, so a catch-all registered last shadows nothing the host already claimed
— but only the host can guarantee that ordering, and a package silently claiming every unmatched URL
in an application is a day of debugging.

`{path}` is unconstrained. A macro flag, **default off**, additionally claims `/`. The direction of
travel is a whole site served from entries; the default is that installing beam-ux never takes a
host's homepage. A host that never calls the macro gets no public surface, which is what keeps beam-ux
headless-installable without inventing a package boundary to protect it.

### 3. The public renderer serves the `site` realm

`realms` is a flat multi-membership list, so one path may be resolvable under several realms' trees.
The public renderer ignores that and walks `site` — the only realm with `routeBase: '/'` and
`guard: null`. `operator`, `tenant`, and `user` are guarded application surfaces served by Frame's SPA
route generator. A public URL resolving into a guarded realm's tree is a leak shaped like a feature.
The macro accepts a realm argument, so this is widenable; `site` is the default and the only shipped
case.

### 4. The renderer reads no realm `guard`; coarse privacy is host middleware

`guard: null` on `site` is not guaranteed — `RealmRegistry::register()` is last-wins by key, and a
fully-private site is a legitimate deployment. The renderer still does not read it: `guard`, like
`routeBase`, is consumed by the **frontend** route generator, and reading it server-side would invent
a second meaning for a field that does not have one.

Instead, two layers:

- **Coarse site privacy is middleware on the host's mount.** A private site mounts the macro inside its
  `auth` group and every entry inherits it.
- **Per-entry gating is the resolution-time hook** (§5).

Neither leaks into `RealmDefinition`, which stays a purely structural five-field DTO.

### 5. Uniform 404; gates fire post-resolution, pre-body-read

An unresolvable path, an unpublished entry, and a gate-denied entry are **indistinguishable** — the
posture ADR-0122 already established for guarded guides. A renderer that answers 403 tells an anonymous
reader which private paths exist.

`EntryPublishGate` and the entitlement hook both fire after the entry resolves and **before its body is
read**, so a denied request never touches the particle store — cheaper, and it closes a timing side
channel that would otherwise distinguish "no such entry" from "entry you may not see."

This ADR fixes **placement**. Gate *semantics* — what "published" means for a draft, and whether
`Mdx::isVisible`'s preview allowlist survives — are settled separately (beam-docs-satellite ticket 15,
reconciling the entry gate stack against ADR-0122).

### 6. Inertia, with a host-supplied page name

The renderer returns an Inertia response, mirroring the incumbent `GuardedGuideController`. Server-
rendering the body to HTML in PHP was rejected: ADR-0122 requires that guarded content never appear in
server-rendered HTML, so it would force two divergent render paths.

No beam package ships a rendered page — no Blade view, no Inertia page. The page name is therefore a
**mount-time argument**, never a hardcoded `content/show`. `page` remains the sole routable `type`,
consistent with `EntrySitemapSource`. A `type`→page map was rejected as a dispatch table with one live
row; widening it later is one argument becoming an array.

### 7. Bodies are compiled on save, cached by particle version, and never compiled in the browser

Three producers invoke one shared compile action:

- the editor save path (`BeamUxEntryBodyController::update`, which already mirrors to disk and is the
  natural hook),
- `RegisterEntriesFromDisk` (operator-batch, console — Node trivially available),
- a `beam:ux:compile` backfill command,

plus a doctor check reporting entries whose artifact is missing or stale (`BeamUxMigrationsAudit` is
the established pattern). The artifact is keyed by particle version, which also makes an ETag free on
the public route.

**There is no silent client-compile fallback.** A public entry with no artifact fails loudly. The
alternative — degrading to shipping the MDX compiler to the browser — is an invisible regression that
surfaces months later in someone's performance audit.

This accepts a Node process at **save** time in production. That is a real deploy-topology commitment
and is made deliberately: every beam-ux host already needs Node to build assets, and compiling where
content changes (rare) rather than where content is read (constant) is the cheaper half of the trade.

### 8. Guarded entries get artifacts too — a refinement of ADR-0122

ADR-0122 specified that a guarded guide's **raw MDX** is fetched post-gate and runtime-compiled in the
browser, so nothing guarded appears in server-rendered HTML. Serving a **compiled artifact** through
the same gate-and-stream route preserves both of that ADR's actual invariants — nothing guarded in
server HTML, and `no-store` post-gate output so a revoked grant bites on the next request — while
removing the MDX compiler from every bundle, guarded and public alike.

This is recorded as a deliberate refinement of ADR-0122 rather than an implementation detail, because
0122 reasoned in terms of raw MDX specifically.

### 9. The realm root is seeded at install; the renderer never writes

`BeamUxEntry::rootFor()` is `firstOrCreate`. A GET that silently INSERTs breaks on read-replica
topologies and races under concurrent first-hits. `splicewire:beam:install` seeds the realm root
explicitly; the renderer treats its absence as "nothing to serve."

### 10. `(parent_id, segment)` becomes unique and indexed

Nothing today prevents two children of one parent from sharing a segment, so two entries can resolve
to the same URL and the walk picks whichever row the query returns first — the same class of
silent-wrong-row bug `BeamUxEntryBodyController` documents finding live on slug ambiguity. The
`beam_ux_entries` migration gains:

- a **unique** `(parent_id, segment)` constraint, with the same `WHERE deleted_at IS NULL` partial
  treatment the `(namespace, slug)` index already carries — without it a soft-deleted page reserves its
  URL permanently;
- the **composite index** the walk needs. `segment` carries no index at all today.

Folded into the existing create migration, which its own docblock records as squashed pre-prod with no
deployed data to preserve.

### 11. Mirror direction: particle-primary, disk is a projection

Disk and CMS are the same authorship level for the same content, mirrored in both directions
(`PlacedDiskMirror` out, `RegisterEntriesFromDisk` in). This is the first place that mirror is given a
stated direction: **the particle is the source of record and the disk file is a projection of it.**

Import therefore **creates but never overwrites** an existing entry without `--force`. A git edit to a
CMS-owned page is a visible no-op someone notices, rather than a silent clobber of authored content.
`UpdateFromNewer` remains the explicit opt-in for the other direction.

### 12. `Route::beamMdxShow()` is deprecated

Because disk MDX and CMS entries are the same authorship level and the mirror unifies them,
`laravel-beam-mdx`'s `beamMdxShow`/`beamMdxPage` macros are not a peer rendering path to be maintained
alongside this one. They serve disk files directly through a build-time bundle, **bypassing the mirror
they predate**. An `.mdx` page is an entry like a `.tsx` page, renders in the beam shell like the rest
of the site, and is served by this renderer.

Deprecated here; retired in a separate effort that converts the remaining disk-backed tracks. Nothing
in this ADR removes the macros.

## Consequences

- **The build-time MDX bundle goes away** as hosts convert. `Mdx::isVisible`'s bundle-exclusion draft
  gate and a host's build-time content glob both become dead machinery, replaced by `workflow_marking`
  + `EntryPublishGate`. Compile-on-save (§7) therefore becomes the only compile path for *all* site
  content, which is what makes the backfill command and its doctor check load-bearing rather than
  housekeeping.
- **Converting a docs track is a genuine performance regression unless §7 lands with it.** Public
  content served from a build-time bundle is code-split and fast today; entries have no bundle. This
  ADR's compile-on-save exists to pay that cost at write time. Shipping the renderer without it would
  make every page download an MDX compiler.
- **Server-side rendering becomes a live question for public content hosts.** Entries render
  client-side like everything else, and Inertia SSR is off by default. For a public documentation or
  marketing site that is an SEO exposure. It is a host-level decision, deliberately out of scope here.
- **Layout/template composition stops being speculative.** A docs-only renderer needed none; a whole
  site of page entries needs shared chrome per section. `type` ∈ {layout, template} becomes near-term
  work rather than a held-open axis.
- **Re-rooting a subtree is a data edit**, which was the point: setting one row's `segment` moves it
  and every descendant, and §1's absolute-segment phase is what makes those rows still resolvable.

## Amendments (beam-docs-satellite ticket 07 — the first host that is not the app)

`splicewire/www` installed this surface off the packages alone. Four things §7 said were wrong on
contact with a browser, and one of them was wrong for every host that had ever run this code.

### A. The artifact must import NOTHING — it is a module that takes its runtime (supersedes §7's shape)

§7 calls the artifact "the ES module the page shell imports" without saying what is inside it. The
build compiled it with MDX's `outputFormat: 'program'`, which emits:

```js
import {Fragment as _Fragment, jsx as _jsx} from "react/jsx-runtime";
```

That is a **bare specifier**. A bundler resolves it; a browser refuses it outright:

> `TypeError: Failed to resolve module specifier "react/jsx-runtime".`

So the module the page shell imports could not be imported by the page shell — on every host, since the
day it shipped. Nothing caught it because the artifact was only ever verified as **compiler output**
(does `compile()` return code?) and never as a **module something loaded the way production does**.

The artifact is now emitted from `outputFormat: 'function-body'` — which has no imports at all and reads
its runtime from `arguments[0]` — wrapped as a real module:

```js
export default function (runtime) { /* … reads Fragment/jsx/jsxs from arguments[0] … */ }
```

The host calls it with its own React: `const { default: Body } = (await import(url)).default(runtime)`.
One shape buys four properties that the alternatives each only partly deliver:

| | import map | `new Function` | **runtime-injected module** |
|---|---|---|---|
| static `import()` | ✅ | ❌ | ✅ |
| no CSP `unsafe-eval` | ✅ | ❌ | ✅ |
| exactly one React | ⚠️ only if mapped to the host's own chunk | ✅ | ✅ |
| no per-host build wiring | ❌ three moving parts | ✅ | ✅ |

**tsx reaches the same contract by a different road**: esbuild's `automatic` jsx emits the identical
bare import, so tsx uses the classic transform against factory names the wrapper binds off the runtime,
with `format: 'cjs'` so the body's own `export default` becomes an assignment legal inside a function.
`module.default(runtime)` returns `{ default: Component }` for every format, so a page shell has one code
path. A tsx body that imports another module fails with a sentence naming the cause — it was equally
unresolvable before, just as a `ReferenceError` at read time.

### B. The artifact's address must include a COMPILER generation, not just the body version (refines §7)

§7 keys the artifact by particle version so that "a stale artifact is not an old file at the same address
but a different address that is simply not there". That reasoning is why the URL can be treated as
effectively immutable — and it has a blind spot: the key hashes the **body**, so changing the **compiler**
moves nothing. Amendment A changed the emitted shape without touching a single body, so every artifact
kept its address and browsers went on serving the previous file from cache. `EntryArtifactStore::GENERATION`
now participates in the hash. Bump it on any change to the emitted shape; it costs one recompile.

### C. Frontmatter is stripped before compiling (refines §7)

`MdxBody::decode()` re-emits the `---` block, deliberately, so an entry round-trips losslessly back to
disk. Plain MDX has no frontmatter concept, so it parsed the block as a thematic break and the keys as
paragraph text — `/beam/docs` rendered "title: Documentation nav_order: 0" above its heading. The
compiler now strips it. The values are already lifted onto the entry's own columns at seed/import time,
so the artifact — which is rendered output — has no use for them.

### D. An unmigrated host resolves NOTHING; it does not fatal (refines §5)

§5's uniform 404 is a property of the triple, but the resolver queried `beam_ux_entries` unguarded. A host
with beam-ux installed and not yet migrated therefore turned **every unmatched URL** into a
`no such table` stack trace, because the host mounts this renderer as a catch-all. `EntryPathResolver`
now returns null when the table is absent — the same `Schema::hasTable` guard, and the same
degrade-don't-fabricate reasoning, as `SeedsEntries::canSeed()`.

### E. A realm root is born published (refines §9)

§9 assigns the realm root to install. It is a `page` row with no segment and no body that no author will
ever transition — but a host binding a `page` workflow makes it workflow-**managed**, and a managed entry
with a null marking is unpublished. Every gate that prunes an unpublished node's subtree then prunes the
entire realm beneath it: `NavProjector` returned `{"items": []}` on a correctly seeded host. `rootFor()`
and the seeders now stamp the published marking, through a helper that no-ops where the optional column
is absent.

## Amendments (beam-docs-satellite ticket 06 — the build)

Three things the implementation settled that this ADR left open or got slightly wrong.

### A. A save-time compile failure is REPORTED, not fatal (refines §7)

§7's "a public entry with no artifact fails loudly" is about the READ path, and it holds there: the page
404s, the artifact route 404s, and the doctor names the entry. It cannot also mean the WRITE path
refuses the save. An author saving half-written MDX is the normal case, and a CMS that rejects the write
because the draft does not compile is a worse editor than one that stores it and says so.

So `BeamUxEntryBodyController::update()` returns the compiler's own diagnostic on the save envelope
(`compileError`) and the save lands. Nothing degrades — there is still no path anywhere that compiles in
the reader's browser, which is the thing §7 actually forbids. The disk batch makes the same trade for
the same reason at a different granularity: it collects per-file failures, reports them, and exits
non-zero, so one broken body does not abort four hundred good ones.

### B. Cacheability is decided by "does anything in the chain declare access?" (refines §8)

§8 requires `no-store` on post-gate output. Applying that to *every* entry would make a public
documentation site uncacheable to buy an invariant it never needed. The renderer therefore asks whether
any node in the resolved chain declares an ADR-0212 token list: if none does, the entry is public by
construction and its artifact is `immutable` (safe, because the artifact's address *is* its version); if
any does, the response is `no-store, private` and a revoked grant bites on the next request.

The check is over the chain, not the target — a public-looking leaf under a gated ancestor is reachable
only through the gate and must not be cached.

### C. The artifact route re-checks realm membership (refines §3)

§3 keeps the renderer inside one realm because a public URL resolving into a guarded realm's tree is a
leak shaped like a feature. The page route gets that for free: the walk only ever traverses rows in the
mounted realm. The artifact route does not — it is addressed by entry **id**, because a compiled module
is not a page and has no segment of its own, and an id is guessable in a way a path is not. It therefore
re-asserts membership explicitly. Without that, the artifact route would be the one door into a realm
this mount does not serve.

## Amendments (beam-docs-satellite ticket 08 — moving a real docs tree onto a host)

### A. The directory chain carries CONTAINMENT, not only the disk-grouping namespace (refines §11)

§11 gave the mirror a direction but described only the *body*. It turns out the inbound leg was carrying
less than the outbound one: `RegisterEntriesFromDisk` derived `namespace`, `slug`, `type`, `format`,
`realm`, `segment`, `title` and both access rights — and left **`parent_id` null on every row**. An
imported tree therefore landed FLAT. Since containment is what decides both the URL (§1, §3) and the nav
(ADR-0165), a host importing a sixteen-page docs tree got sixteen orphans and had to rebuild the tree by
hand afterwards — with nothing in git recording it. The directory chain said the whole structure and none
of it crossed.

**A file is contained by the file named for the directory it sits in.** Stated on the coordinate both
directions of the mirror agree on: an entry at namespace `a.b.c` is parented by the entry at
`(namespace: a.b, slug: c)`; at namespace `a` by `(namespace: null, slug: a)`; at the scan root by the
batch's `--under` (default: the realm root). Because `DefaultPlacement` writes back out as
`{namespace}/{type}/{slug}.{ext}`, reading the parent off the namespace rather than the raw path makes
import and mirror agree **by construction** — a tree the mirror exports re-imports with the same parents,
type segment and all.

Three consequences worth stating:

- **`parent_id` is the one containment field the disk owns, and frontmatter does not.** Every other field
  is frontmatter-only on the stated ground that *a URL is never guessed*. A parent cannot follow that rule
  because it is a foreign key: frontmatter could only name it by inventing a second address space for
  something the directory tree already states unambiguously. Segment stays frontmatter-only, so the disk
  says *what contains what* and the file says *what it is called in a URL* — and the two cannot disagree.
- **Shallowest-first.** The parent must exist before a child names it, and the directory iterator's order
  is a filesystem detail that differs by platform. The batch sorts by namespace depth. (Written the
  obvious way, the guard for this passed with the sort deleted, because this machine's iterator happened
  to yield a workable order — the test now forces the walk deepest-first.)
- **A missing realm root is looked up, never created.** §9 gave root provisioning to install on the
  ground that a row nothing declared should not appear as a side effect of another operation. That
  argument was made about a GET and holds for a batch too: a host that has not installed imports flat,
  exactly as before, and its missing root is already a doctor finding.

A named parent that does not resolve — a file nested under a directory with no file of its own — falls
back to `--under` rather than erroring. A gap in the chain is a flatter tree, not a failed import.

## Alternatives rejected

- **A materialized `resolved_path` column** — one indexed lookup instead of a walk, but a second source
  of truth whose invalidation must cascade transitively on every subtree move, and moving subtrees is
  the entire point of containment. The cost is certain; the load problem is hypothetical. **Held open
  as the intended eventual shape**, not dismissed: §1's two-phase lookup exists precisely because the
  two halves of the segment grammar are not uniformly queryable in reverse, and a materialized path is
  the only representation where they would be.
- **Route-table projection at build** — generating real routes per entry. Makes public URLs a build
  artifact of *content*, directly contradicting re-rooting by editing a row.
- **A separate `laravel-beam-site` package** owning public serving, keeping beam-ux authoring-only.
  Splits the containment model from the one thing that makes containment observable. The
  headless-installability concern it answers is answered by the opt-in mount macro instead.
- **Resolving a realm from the request and walking any realm's tree** — see §3.
- **A `beam.ux.public.middleware` config key** for the private-site case — the mount macro already
  takes middleware, and the host already owns the wire.
