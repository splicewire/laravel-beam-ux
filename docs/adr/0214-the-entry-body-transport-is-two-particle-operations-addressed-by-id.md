# ADR-0214 — The entry-body transport is two particle operations, addressed by id

Status: **accepted** — beam-docs-satellite ticket 24, 2026-08-23.
Date: 2026-08-23
Builds on: **ADR-0116** (the host owns the wire and the policy), **ADR-0160** (operation kind is the
transport axis), **ADR-0163** (typed-client codegen), **ADR-0138** (the entry body rides a versioned
particle), **ADR-0209 §7** (compile-on-save, no client-compile fallback), **ADR-0210** (a contribution
registers DOWN; beam never learns a consumer's name), **ADR-0092** (the composition seam).
Retires: `Splicewire\Beam\Ux\Http\Controllers\BeamUxEntryBodyController`, its
`App\Http\Controllers\Api\V1\` fork, the `Route::beamUxEntries()` macro, and the `beam.ux.api_root` /
`beam.ux.route_name` config keys.
Hands across: the registry-derived **mounting** of a resource's operations —
`particle-contribution-seam`, not this map.

## Context

`splicewire/laravel-beam-ux` and `splicewire-app` each shipped a `BeamUxEntryBodyController` with the
same two actions over the same URLs. The host copy predates the package one and never followed it, so
the two had drifted six ways: the package compiles the artifact on save, view-gate-filters the body it
returns, mirrors the saved body to disk, falls back to `ThemeSchemas` for a `theme` entry, reports a
`compileError`, and resolves the entry with an explicit `namespace` scope. The app's copy does none of
it, and had additionally fataled outright from ticket 07 until ticket 09 patched its constructor arity
in place.

Ticket 09 declined to delete it, because the swap was not mechanical. The app's mount declares its
shape — `TierRoutes::mount(...)->beam()->returns(BeamUxEntryBodyData::class)` — which is what satisfies
`UndeclaredSurfaceAudit` and feeds ADR-0163 codegen a typed client hook, while the package macro
registers two plain routes that do neither. So the question looked like *where does the declaration
belong*: the macro learns to declare, or the host keeps a thin declaring wrapper.

Both answers are wrong, because the controller should not exist.

`BeamUxEntryData` has carried `#[ParticleResource(key: 'beam-ux-entry')]` since it was written.
beam-ux already ships two `#[ParticleOp]`s over that same resource — `EntryWorkflowShowOp` and
`EntryWorkflowTransitionOp` — and beam-ux has already performed this exact dissolution once:
`MirrorStatusRowData`'s docblock records a bespoke controller plus `#[ResponseFromData]` envelope
*"retired in favor of this once it was clear every row is nothing but one `BeamUxEntry` plus computed
decorations, i.e. exactly what `#[ParticleResource]` already exists to serve."*

`BeamUxEntryBodyController` was the last bespoke controller in the package, and it was bespoke for
exactly one reason: **it addresses by `slug` where the particle pipeline addresses by `id`.** Every
ugly thing in it descends from that. The `?namespace=` query parameter exists to disambiguate a slug.
The null-namespace tiebreak exists to disambiguate a slug. And both docblocks record the bug that
motivated them — a namespaced `theme` and a null-namespace `page` sharing one slug, where the
ambiguous `first()` *"picked whichever row's uuid sorted first, silently serving/saving to the WRONG
entry with no error."*

## Decision

### §1 — The transport is two particle operations on the existing `beam-ux-entry` resource

- **`body`** — `OperationKind::Read`, `output: BeamUxEntryBodyData`. Reads the entry's particle body
  through the resolved `StorageDriver`, applies the `ViewGateFilter`, resolves the schema (inline
  `schema_ref`, else `ThemeSchemas` for a `theme` entry, else null).
- **`save-body`** — `OperationKind::Write`, `input: BeamUxEntryBodyInputData`, `output:
  BeamUxEntryBodyData`. Writes through the `PolicyWriteGate`-backed `ParticleWriter`, binds a
  first-write particle id, mirrors to disk at the resolved `FilePlacement`, compiles the artifact
  (ADR-0209 §7, reported and not fatal), and re-reads.

Naming follows the sibling pair, which names the read for its subject (`workflow`) and the write for
its verb (`transition`). The write's payload becomes a declared `input:` validated through
`validateAndCreate()`; today's endpoint accepts any shape off `$request->input('body')`.

An operation's `output:` slot is already one of `UndeclaredSurfaceAudit`'s legal declaration sites and
source 3 in `RouteReturnType`. So the question this ADR was opened to answer **dissolves**: there is
no macro to teach to declare, no host wrapper to keep, and no `#[ResponseFromData]` to add. The
declaration is the operation.

### §2 — Addressing moves to `{id}`, and the disambiguation machinery deletes with it

`?namespace=`, the null-namespace tiebreak, and the two-query `resolveEntry()` all delete. An id
cannot be ambiguous, so the defect they were built to mitigate becomes unrepresentable rather than
mitigated.

Three of the six call sites already hold the row and were passing a slug for no reason. The two that
genuinely derive one are the in-page canvas editor and `MainframeHost` — and `MainframeHost` derives
its slug from the **Inertia component name**, so on every rendered entry it probes for `site-entry`, a
row that does not exist, while the page being viewed *is* an entry. The renderer already puts the
entry id in its props. So id-addressing does not merely avoid the ambiguity; it is the fix for a
separate live defect that was waiting on a decision about where the slug comes from.

### §3 — Authorization is declared on the operation, not inherited from the mount

`ability: 'ux.author'`, matching both siblings on the same resource.

ADR-0116's *host owns the wire and the policy* stands for **placement**. What it must stop meaning is
that the surface's authorization is whatever the host's enclosing group happens to be — because the
two live hosts disagree: `splicewire/www` mounts under `auth` + `verified` with no tenancy at all
(*"the op's own `ability: 'ux.author'` … is the real gate; this group is just auth+verified"*), while
`splicewire-app` mounts under `api` + `InitializeTenancyBySubdomain` + `EnsureTenantNotSuspended` +
`BlockTenantWrites` + `PreventAccessFromCentralDomains` + `auth:sanctum` + `ResolveTenantUser`. One
surface, two authorization stories, neither written down on the surface. The declared ability is the
one that travels.

### §4 — The read mounts `GET`

`Route::particleOp()` takes `$options['method']`, defaulting to `post` **regardless of kind**, and one
estate call site already uses `get`. `api-surface-coherence` ticket 30 made a GET operation's input
correct — rejection reads *"query on a GET, body otherwise"*, and `ParticleOperationParameterStrategy`
publishes its parameters on the query axis — so the mechanism is complete and unused.

The body read is the hot path on every editor open, takes no input, and is idempotent. It mounts GET.

`EntryWorkflowShowOp` is also `kind: Read` and mounts POST. That is the older operation being wrong,
not a precedent: **`Read` ⇒ `GET` should be `particleOp`'s default**, which is a beam-core doctrine
change touching every Read op estate-wide and is therefore filed to `particle-contribution-seam`
rather than decided here.

### §5 — beam-ux registers its own operations; no host names a beam-ux class

`~/Herd/splicewire/config/beam/core.php` currently hardcodes two beam-ux FQCNs —
`EntryWorkflowShowOp::class` and `EntryWorkflowTransitionOp::class` — in the **host's** config, with a
beam-ux-specific comment explaining them, because `discoverParticleAttributes()` reads
`beam.core.particle.classes` / `.discover_paths` and no-ops when both are empty.

That is the exact inverse of the invariant ADR-0210 established for this package's seed manifest
(*"consumers register DOWN into beam's manifest; beam-core never learns a consumer's name"*), and of
`particle-contribution-seam` 04 §A1's ruling that **the contributor declares and the owner names
nothing**. It is not a new decision — it is a settled one that has never been applied to the
*operation* registry.

beam-ux registers all four of its operations from its own `packageBooted()`, via ticket 07's direct
`bound()` → `make()` → `register()` idiom. The host config entries delete.

### §6 — `Route::beamUxEntries()` and its two config keys delete outright

Nothing but that macro reads `beam.ux.api_root` or `beam.ux.route_name`, so retiring it retires both.
There is no deprecation window: every consumer is in-estate and moves in the same pass, and a macro
left standing over a deleted controller is worse than an absent one.

**No replacement macro.** An earlier draft of this decision kept the seam as
`Route::beamUxEntryOps()`, mounting the four operations for the host to place. That is a band-aid: it
fixes mounting and leaves registration a host-config copy-paste, and it is beam-ux-shaped where the
defect is estate-shaped. With §5 landed the registry knows every operation for `beam-ux-entry`, so
the general answer is that **`Route::particleResource()` mounts the resource's registered operations**
— verb from kind, per §4 — and a host writes one line for the whole surface. That belongs to beam core
and to `particle-contribution-seam`; this ADR commits beam-ux to owning no mount macro of its own.

Until it lands, the two hosts mount with `Route::particleOp()` directly, and `www`'s existing two hand
lines are the ones that delete when it does.

### §7 — beam-ux's Data classes carry `#[TypeScript]`, and the host subclass deletes

`App\Data\BeamUxEntryBodyData` exists solely to carry `#[TypeScript]`, and the package class's
docblock justifies the omission as *"so the package never forces a typescript-transformer dependency
on every consumer."*

That was never true of beam-ux, which hard-requires `splicewire/laravel-beam`, which requires
`spatie/typescript-transformer`. It is also unique to beam-ux: beam core carries the attribute on 7
Data classes, beam-accounts 12, beam-workflows 13, beam-commerce 24, tower 155 — and
`splicewire-app`'s generated tree already resolves types from eight vendor namespaces. beam-ux's are
the only package Data classes absent from it.

All five beam-ux Data classes take `#[TypeScript]`; the host subclass deletes. The generated alias name
`BeamUxEntryBodyData` is unchanged, so no consumer moves.

The subclass's stated rationale was doubly stale: it claims to surface the type *as*
`App.Data.BeamUxEntryBodyData`, and `surgeon-audit-viability` ticket 34 dropped that remap — every type
now emits at its real PHP namespace.

## Consequences

- One implementation of the entry-body transport, at one address shape, on one mechanism. Today there
  are two implementations at **three** prefixes: `beam/ux/entries/{slug}/body` and
  `api/v1/beam/ux/entries/{slug}/body` in `splicewire-app`, `/api/beam/ux/entries/{slug}/body` on `www`.
- Six frontend call sites migrate from slug to id. The `MainframeHost` defect closes with them.
- The write's payload becomes typed. The read becomes cacheable.
- `www`'s `config/beam/core.php` stops naming beam-ux classes; the app's controller, its route block
  and its DTO subclass all delete.
- The resource segment stays the single hyphenated `beam-ux-entries`. A `/` in a resource name breaks
  Wayfinder's generated-helper relative-import depth calculation, which splits the route *name* on `.`
  only — recorded on `www`'s mount and preserved here deliberately.

## Alternatives rejected

**A read-only `#[ParticleResource]` for the show half.** `MirrorStatusRowData` is a second
`readOnly` resource over the same `BeamUxEntry` model, so the precedent exists and it would have
bought a `GET` without touching `particleOp`'s default. Rejected because a `#[ParticleResource]` is a
**browsable** thing — it carries `label`, `group`, `section`, `icon` and lands in Frame's admin browser
as a table. Mirror-status earns that; it *is* a file-status table. A per-entry body is not a
collection, and would have appeared as a nonsense admin table to buy a verb that §4 gets anyway.

**Teaching `Route::beamUxEntries()` to declare.** Answers `UndeclaredSurfaceAudit` and leaves every
other defect — slug addressing, undeclared input, mount-inherited authorization, host-named classes —
exactly where it was.

**A host-local declaring wrapper over the packaged controller.** Same, plus it makes the app's copy a
permanent fork by design rather than by accident, and answers nothing for `www`.

---

## Amendments (2026-08-26, beam-docs-satellite ticket 30, on landing)

Four of this ADR's stated facts were re-measured while landing it. The DECISIONS all survive; four
premises did not, and three of them would have broken something if executed literally.

### §6 is wrong: neither config key may delete

> *"Nothing but that macro reads `beam.ux.api_root` or `beam.ux.route_name`, so retiring it retires
> both."*

Both have other readers, and both readers are load-bearing:

- **`beam.ux.route_name`** is read by this package's OWN `WiresPublicSurface::bootPublicRouteMacro()`
  (`src/Concerns/WiresPublicSurface.php:100`) to name the public-entry **artifact route** — the route
  ADR-0209 §7 relies on for its immutable cache header. Deleting the key renames that route.
- **`beam.ux.api_root`** is read by **beam core's published Scribe stub**
  (`splicewire/laravel-beam/stubs/scribe/scribe.php:115`) to derive the OpenAPI **extraction
  include-list**, asserted by `ScribeOutputContractAudit` and pinned by `PublishedScribeStubTest`, and
  documented in ADR-0211. Three hosts carry the published copy (`~/Herd/schemastud/config/scribe.php`,
  `~/Herd/splicewire/config/scribe.php`, `~/Herd/splicewire-app/`). Deleting the key silently drops
  `beam/ux/*` from the generated spec.

**Amended:** the macro `Route::beamUxEntries()` deletes; **both config keys stay**. `api_root` keeps
positioning the OpenAPI extraction window; `route_name` keeps naming the public artifact route. Their
docblocks in `config/beam/ux.php` need rewriting away from the retired macro, which is a doc change,
not a deletion.

### §6's "no deprecation window" rested on a host count that was short by one

> *"every consumer is in-estate and moves in the same pass"*

Measured: **three** hosts mount the macro, not two. `rushing/audiostud` mounts it at
`app/Providers/DomainResourceServiceProvider.php:156`, with two live frontend call sites
(`resources/js/layouts/beam-ux/beam-ux-services.ts`, `ux-editor.tsx`) plus generated Wayfinder
actions and routes. audiostud appears nowhere in this ADR or in the ticket that executes it.

**Amended:** the operations land **additively** — registered and callable while the macro and
controller still stand — and each host migrates and deletes on its own schedule. That is not a
softening of "one implementation"; it is the same end state reached over three host migrations
instead of two, which is what the corrected count actually permits. The macro's deletion is gated on
the last host, not on this package.

### §5's count of host-named classes is short by three

`~/Herd/splicewire/config/beam/core.php` names **five** `Splicewire\Beam\Ux\*` classes, not two: the
two operations §5 names, plus `BeamUxEntryData`, `MirrorStatusRowData` and `SitemapHealthRowData` —
the three RESOURCES. §5's argument (a contributor declares; the owner names nothing) applies to all
five identically, and `particle-contribution-seam` 07's ratified idiom registers a resource exactly
the way it registers an operation.

**Amended:** `WiresParticleDeclarations` registers **all seven declarations** — three resources and
four operations. Registration is idempotent by key, so a host that has not yet deleted its config
entries registers the same declaration twice and gets the same result; the host lines are therefore
safe to delete independently.

### ~~The starters mount nothing, so there is nothing to propagate~~ — WRONG, corrected 2026-08-26

Ticket 30's step 4 has `laravel-beam-starter` land the change and propagate by merge down the
restored chain. Measured: all three starters `require` `splicewire/laravel-beam-ux`, and **none**
calls `Route::beamUxEntries()` or names a beam-ux class in config. The starters are not consumers of
this transport, so §6's estate sweep does not reach them and the "prove it OTB on a starter"
acceptance has nothing to prove — the operations are registered by the package, so a starter gets
them by installing it and mounting nothing.

**Amended (beam-docs-satellite ticket 36, 2026-08-26): every word of that paragraph is false.** All
three starters call `Route::beamUxEntries()`, on the same line of the same file —
`routes/web.php:21` in `laravel-beam-starter`, `laravel-satellite-starter` and
`laravel-tower-starter` alike (they are forks, so the line propagated once and sat in all three).
Each also ships a `bodyClient` fetching the literal `/beam/ux/entries/{slug}/body` and a generated
`actions/Splicewire/Beam/Ux/Http/Controllers/BeamUxEntryBodyController.ts`.

Two things follow.

**§6's deletion is not unblocked by the last SITE.** `splicewire/www` (ticket 37) and
`rushing/audiostud` (ticket 36) are both off the macro, and audiostud was named "the sole remaining
gate" on the strength of the paragraph above. It was not. The macro has three more callers, and
deleting it today breaks a fresh starter install at boot, not at first request — a route file that
calls a missing macro throws before any route is served.

**One of them is already broken that way.** `laravel-satellite-starter/storage/logs/laravel.log`
records `InvalidArgumentException: Attribute [beamUxEntries] does not exist` thrown from
`routes/web.php:21` on 2026-08-21 — the macro was not registered in that install at all. So the
starter chain's mount is not merely a stale consumer of this transport; at least one link of it does
not boot. That is the starter chain's own ticket, not this ADR's.

This is the fourth time a count on this transport has been wrong, and the third time the miss was
found by executing a ticket rather than by reading one. The pattern each time: the enumeration
grepped the layer it expected the consumer to be at (the interface, the sites, the ADR's own host
list) rather than the symbol being retired.

### What DID land in this pass

`src/Particle/EntryBodyShowOp.php`, `src/Particle/EntryBodySaveOp.php`,
`src/Particle/EntryBodyEnvelope.php`, `src/Data/BeamUxEntryBodyInputData.php`,
`src/Concerns/WiresParticleDeclarations.php`, `#[TypeScript]` on all six Data classes (§7), and
`tests/EntryBodyOpsTest.php`. `src/Particle/` was chosen over a `src/Workflow/` sibling because the
workflow pair lives there for its CONCERN — they are thin adapters over `WorkflowActuator`, which is
in that directory. The body pair's collaborators are spread across `Storage/`, `Compile/`,
`Placement/`, `Canvas/` and `Schema/`, so no existing concern directory is theirs; `Particle/` names
what they ARE rather than borrowing a concern they do not have.

One departure from §3, made on evidence that post-dates this ADR: both operations declare
`abilityModel: false`, not the bare `ability: 'ux.author'` §3 specifies. `ux.author` is an
entitlement key, and `particle-operation-surface` ticket 08 measured that the null slot routes it to
the policy plane — where it answers correctly only by accident, and where every audit reads it as
correct. §3's intent (the declared ability is the one that travels) is served by the flag, not
defeated by it.

**Still open, per host:** the controller, the macro, the app's fork + DTO subclass, the host config
entries, and the six frontend call sites' slug → id migration.

---

## Amendments (2026-08-26, beam-docs-satellite ticket 34, on migrating the first host)

### §2's "the renderer already puts the entry id in its props" is true and insufficient

> *"Three of the six call sites already hold the row and were passing a slug for no reason. The two
> that genuinely derive one are the in-page canvas editor and `MainframeHost` — and `MainframeHost`
> derives its slug from the **Inertia component name** … The renderer already puts the entry id in
> its props."*

Both sentences are correct. Together they imply the `MainframeHost` case is a matter of reading a
prop that is already there, and it is not.

`MainframeHost` is `createMainframeHost` in **`@splicewire/beam-mainframe`** — a package this ADR
does not name, and a **seventh** repo on this transport after the six ticket 30 counted. It resolves
one entry key through a three-branch chain:

```
overrideEntrySlug()            // ?beam_entry=<slug>
  ?? propSlug                  // props.entry.slug  — props.entry.id is right beside it, unread
  ?? entrySlugFor(component, componentToEntry)
```

The third branch is a **host-authored, compile-time frontend map from Inertia component name to
entry**. No such map can carry a per-database uuid: it differs in every deployment, including a fresh
install. So the id exists for the middle branch only, the factory carries one key rather than two,
and a host cannot hand these operations an id today no matter what it reads off its props.

Measured at `splicewire/www` while landing ticket 34: five consumers of the host body client, three
of which hold the row and migrated cleanly, and two — `editor/mount.tsx` and
`layouts/beam-ux/mainframe-host.tsx` — which are mainframe-fed and could not.

**Amended:** the slug → id migration at a mainframe-hosted call site is **not** a call-site edit. It
needs a decision about where the entry key comes from — a resolver on the wire, a second key through
the factory, or retiring the component-name fallback entirely — and that decision is filed as
beam-docs-satellite ticket 37, not taken here. §6's deletion of `Route::beamUxEntries()` is gated on
it, on top of the per-host gating the 2026-08-26 amendment already added.

### What §1/§4/§5 look like once actually served

All measured at `www` against a live entry, through the real router:

- `GET .../op/body` → 200 `{slug, id, type, schema, body, compileError}`.
- the same with `?namespace=theme` → **422**. `input: false` on a GET means the retired disambiguator
  fails loudly rather than being ignored, which is a stronger outcome than §2 claimed for it.
- `POST .../op/save-body` `{body: …}` → 200, `compileError` null, disk mirror written.
- the same with `{bodyy: …}` → **422**, where the retired controller silently saved `{}`.
- either, as a user without `ux.author` → **403**, so §3's declared ability is live on the mount and
  not inherited from the enclosing group.
- §5's config deletion changes nothing: the unfiltered registries either side of removing all five
  host-named FQCNs hold **13 resources and 6 operations, identical in content and registration
  order**.

The published spec agrees with the declarations: `body` carries `parameters: []` and no
`requestBody`; `save-body` carries one typed `BeamUxEntryBodyInputData`.

**The 422 is the host's, not the operation's** (measured at `rushing/audiostud`, ticket 36). The same
`?namespace=` read there is a **302 with session errors**, carrying the identical
``The `body` operation accepts no input.`` message. The operation raises a `ValidationException`
either way; what differs is whether Laravel renders it as JSON, and that host calls
`shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` in `bootstrap/app.php`, which **replaces** the
default predicate rather than widening it — so `Accept: application/json` stops counting outside
`api/*`. Worth recording because "it 422s" reads like a property of the transport and is not one: a
host whose machine surface does not live under `api/*` gets a redirect, and a `fetch()` client sees a
followed redirect and an HTML body instead of a status it can branch on. `403` is unaffected
(authorization does not route through that predicate), so §3's ability check reads the same on both.

### A type-system fact worth recording, because it decided a rename

`UxBuilderClient` moved to `loadBody(id)` / `saveBody(id, body)` in ticket 33. A host implementation
still declaring `loadBody(slug: string, namespace?: string | null)` assigns to that signature with
**zero** TypeScript errors — a function with extra *optional* parameters is assignable to one with
fewer, and a slug and an id are both `string`. So this ADR's migration is invisible to the compiler
end to end.

That is why `Region.record` was renamed to `Region.recordId` rather than merely redocumented: it is
the one field every host constructs by hand, and renaming it is the only mechanism available that
turns a silent 404-on-every-editor-open into a compile error.
