# EP-07a — Manifest Profiles, server side (PROOF)

**Slice.** EP-07 was split at claim into 07a–07d (recorded in the EP mission PROGRESS). **EP-07a is the
server-side profile slice**, PHP-only, across two repos. It is what EP-06 d8 depends on.

ADRs: ux-0228 d6/d7 (one vocabulary; a profile per realm), ux-0227 d1 (the hosted-site code boundary),
app-0112 (the Manifest grammar).

## Candidates to review

| Repo | Branch | Tip |
| --- | --- | --- |
| `splicewire/laravel-beam-ux` | `slice/ep-07a-manifest-profile` | `3057997` (off `9c01f2e`) |
| `schemastud/laravel-blockdoc` | `slice/ep-07a-x-admits-code` | pushed (off `782ade7` = origin/main) |

## What it does, by commit (beam-ux)

- **(a) HTML base profile** (`9616722`) — `HtmlManifestProfile::manifest()` ships the intrinsic HTML
  elements as a v1 Blockdoc manifest: node name = JSX tag, OPEN attributes
  (`additionalProperties:true`, the stable d3 `id` typed), open containment (a container admits the
  single `html` category; a void element is a childless, text-less leaf). Entry shape mirrors
  `ManifestBuilder::node()`.
- **dep** (`6929fcd`) — beam-ux now requires `schemastud/laravel-blockdoc` (the reuse path for
  `ManifestBuilder`/`#[NodeType]`/the grammar). Not on Packagist → each consumer's ROOT `composer.json`
  needs the git `repositories` entry. See `repositories-census.md`: **5 resolve already** (flagship,
  Tower, tower-market, tower-starter, satellite-starter); **11 need the entry** (8 beam-family + www +
  numero + audiostud).
- **(c)-1 unknown-name + code refusal** (`df6a65b`) — `ProfileLegality::assert()` refuses, by node
  id + path in one 422 on `body`: an unknown block name (an unregistered component lands here); and when
  `x-admits-code:false`, an **opaque node** (verbatim code) + an **expression prop** (ux-0227 d1).
- **(b)-1 residency resolver** (`9aba82d`) — `EntryResidency::isCentralResident(BeamUxEntry)` is the
  blessed helper (asks laravel-beam's `TenancyContext`). **x-admits-code keys on WHERE THE ENTRY LIVES**,
  not the actor or request realm, so an operator editing a tenant-owned entry cannot inject code; a
  single-tenant host (no split) is central, so starters keep authoring code. **Fails closed** — an
  undeterminable connection is treated as tenant-owned. `ManifestProfileResolver` ships the HTML base
  with `x-admits-code` from residency.
- **(c)-2 containment** (`e873b97`) — a node must sit under a parent that admits its category (root =
  `doc.admitsChildCategories`); text only where `admitsText`; a fragment (name null) is transparent.
- **save-wiring** (`29dcfd0`) — `EntryBodySaveOp::handle()` runs `ProfileLegality` against
  `ManifestProfileResolver->resolve(entry)` after the well-formed pass, before the write, for a
  JsonDoc-shaped body. Proven through the real op (an out-of-profile `<Marquee>` → 422 naming id+path).
- **(b)-2 host components + JS-registry check** (`ed59698`) — the resolver composes the host's placeable
  `#[NodeType]` components onto the HTML base (through a bound `ManifestNodeSource` + `ManifestBuilder`,
  ordered-array: the host profile may not redeclare a base name). `ManifestRegistryCheck` compares the
  PHP `#[NodeType]` names with the host's JS component registry both ways so the two can't drift; the JS
  registry's name set is host-supplied.
- **(c)-3 attrsSchema** (`3057997`) — props are validated against the node's `attrsSchema` (opis): a
  literal prop of the wrong type, an undeclared prop (`additionalProperties:false`), or a missing
  required prop is a 422 by id+path. An expression prop is dynamic — present (satisfies required), type
  relaxed to any.

## x-admits-code grammar (laravel-blockdoc candidate)

`ManifestBuilder::build()` now emits `x-admits-code` in the envelope. A source opts out through the new
**optional** `DeclaresCodeAdmission` contract; a source that does not implement it admits code (`true`),
so every existing `ManifestNodeSource` implementer (Tower's `ContentBlockManifestSource`, etc.) is
unchanged. Full blockdoc suite 80 green.

## `dynamic` is a flag, not code

As directed (ux-0227 d1): `JsonBlockNode.dynamic` is a boolean flag and carries **no** executable source
in this shape, so it is NOT refused on its own. The code vectors are the opaque node, the expression
prop, and the MDX expression (MDX lives in the source path, not a Blocks body).

## Tests

- beam-ux Manifest suite: 29 green (`HtmlManifestProfile` 5, `ProfileLegality` 13, `EntryResidency` 4,
  `ManifestProfileResolver` 4, `ManifestRegistryCheck` 4 — counts across the increments).
- `EntryBodyOps` 28 green (includes the save-wiring Feature test).
- laravel-blockdoc: `ManifestCodeAdmission` 2 + full suite 80 green.

## Known pre-existing reds (NOT this slice)

The full beam-ux suite shows **9 failures in `FrameResourcesCollectorTest`** (a host resource-nav
expander). **Verified identical at the base `9c01f2e`** (checked out and re-run: same 2 errors + 7
failures) — a package-harness host-registry gap, untouched by EP-07a (which adds `src/Manifest/*` and one
guard in `EntryBodySaveOp`, neither referenced by that collector).

## Deferred to the host / later sub-slices (by design)

- The flagship **realm-bind** (the host's per-realm `ManifestNodeSource`) is 07a's host step — a primary
  proof, later.
- The **JS component registry** source for `ManifestRegistryCheck` is host-supplied (the flagship JS
  build emits it).
- **07b** (canvas reads the profile + the `@schemastud/blockdoc` TS `x-admits-code` grammar), **07c**
  (JSX-tag node names + Tower slot migration), **07d** (the form Code Component) follow as their own
  candidates.
- **EP-06 d8** rides this: it narrows the save check to only the nodes a save adds or changes (by id).
