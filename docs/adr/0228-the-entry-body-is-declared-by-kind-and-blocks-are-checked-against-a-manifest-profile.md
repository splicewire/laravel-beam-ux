# ADR-0228 — The entry body is declared by kind, and blocks are checked against a manifest profile

Status: **accepted**. Decided in editor-parity ticket 11, 2026-10-15.
Date: 2026-10-15
Builds on: **ADR-0214** (the entry-body transport), **ADR-0225** (one history; a restore rolls forward into a draft),
**ADR-0226** (disk comes back by hand, as drafts, through Files actions), **ADR-0227** (a tenant-owned entry holds
blocks, not code), **ADR-0209 §7** (Node runs where content changes, not where it is read).
Cites, without editing: `splicewire/splicewire-app docs/adr/0112-ship-blockdoc-as-is-defer-the-open-standard-our-contribution-is-a-schema-grammar-contract-layer-over-any-block-serialization-not-a-competing-block-format.md`
(the Block Manifest is a grammar over any serialization, and reading never runs it) and
`splicewire/splicewire-app docs/adr/0164-beamux-file-format-is-a-sibling-axis-of-type-not-a-fifth-kind.md`.
Amends: ADR-0214 §1 (`save-body`'s input carries a declared JsonDoc, not an `array`, and source is written by its own
operation). ADR-0227's first consequence (its blocks-only check is the profile flag in decision 7).
Companions: beam JS `docs/adr/0003-a-style-is-a-block-style-compiled-at-render-time.md` (the Block Style),
beam JS `docs/adr/0005-one-editor-the-host-kit-lives-in-beam-ux-and-a-host-conforms-by-mounting-it.md` (the kit move
the rename rides), and beam JS `CONTEXT.md` (**TSX Lens**, **JsonDoc**, **Block Manifest**, **Manifest Profile**,
**Body Kind**, **Opaque Island**).

## Context

The editor's envelopes are declared Data classes at a legal site, but the document inside them is not.
`BeamUxEntryBodyInputData` and `BeamUxEntryBodyData` carry `public array $body` (`src/Data/BeamUxEntryBodyInputData.php:45`,
`src/Data/BeamUxEntryBodyData.php:80`). The JsonDoc's types are hand-written TypeScript (`@splicewire/beam-ux`
`src/blockdoc/json.ts:28-72`). `JsonDocPrinter` is kept by hand as a "behavioral mirror" of the client's printer
(`src/Codec/JsonDocPrinter.php:8`). The canvas hands its inspector an inert schema because "A JsonDoc has no formal
JSON-Schema grammar (yet)" (`beam-ux/src/canvas/VisualEditor.tsx:47-50`).

A `tsx` body has two shapes, told apart by `array_is_list` (`src/Codec/TsxBodyCodec.php:59-73`): a JsonDoc list from the
canvas, or `{source, body_style, preamble}` from a disk file. The canvas opens only the first. Given the second, it opens
the slug's seed tree, and its first Save replaces the page. A theme stored as a `tsx` entry decodes to `''`, because it
is neither shape.

Three vocabularies describe what an author can place. The canvas uses a component map with no schema
(`beam-ux/src/canvas/context.tsx:31`). The MDX kit keeps a local copy of the manifest's shape
(`beam-mdx/src/editor/manifest.ts:11-13`). Compositions serve manifests built from `#[NodeType]` Block classes
(`schemastud/laravel-blockdoc`).

Nodes have no identity. `EntryBodySaveOp` documents the cost. An editor who can't view a gated subtree saves a body
without it, and the subtree is lost, because nothing tells an omission from a deletion
(`src/Particle/EntryBodySaveOp.php:69-76`).

Measured 2026-10-15 over 113 central entries at four hosts (the beam site, audiostud, the flagship and
`laravel-beam-starter`): no stored body is a JsonDoc yet. One `tsx` page is stored as source, and every other `tsx`
page holds `[]`.

## Decision

1. **The entry declares its body's kind; the data doesn't.** A `tsx` page holds **blocks** (a JsonDoc), and a component
   holds **source**. A theme holds a **form**: fields checked against the entry's own `schema` and written through
   `CssBodyCodec`, whatever its format column says. Editor-parity ticket 15 decides what an MDX page holds. Until then it
   holds source. No operation takes a bare `array`.
   *Amended by ADR-0231 decision 1 (2026-10-15): a component entry holds blocks, as an Authored Component, or, on a
   central entry only, source.*
   *Amended by ADR-0233 decision 1 (2026-10-16): an MDX page holds blocks. MDX names its File Language, not its kind.*
2. **The JsonDoc is a declared Data union.** `JsonNode` is a `PropertyMorphableData` keyed on `kind`, with three arms:
   block, text and opaque. `rushing/laravel-data-nav`'s `NavNode` is the precedent. A block's props keep their five
   kinds. The TypeScript types and the JSON Schema are generated from the classes. `json.ts`'s hand-written types and
   `JsonDocPrinter`'s mirroring retire.
   *Amended by ADR-0229 decision 3 and ADR-0231 decision 8 (2026-10-15): a block's props have seven kinds, with `field`
   and `prop` added.*
   *Amended by ADR-0234 decision 13 (2026-10-16): a block may carry a Click Event, printed as `bdEvent`.*
3. **Every block has a stable id**, a UUIDv7 as in blockdoc. The TSX printer leaves ids out of source. Wherever source
   comes back in, ids are reattached against the Working Head by content (the `NodeIdRematcher` precedent in
   `schemastud/laravel-blockdoc`). A node with no match gets a fresh id.
4. **A block carries its Block Style as a field:** `style: { layers: [{ breakpoint: ?name, state: ?name, declarations:
   {property: value} }] }` (beam JS ADR-0003). The printer writes it as `bdStyle`, and the lens reads it back. A legacy
   inline `style` string becomes the base layer at the next save. Props stay the element's own attributes.
5. **An Opaque Island is legal on a central entry.** Its `reason` is an enum and its `source` a string, both checked for
   shape only. The lens's round-trip tests certify an island; the schema doesn't. A tenant-owned entry refuses islands
   (ADR-0227 decision 1, enforced through decision 7).
6. **There is one vocabulary: the Block Manifest, served per profile.** The canvas's palette and inspector, the MDX kit
   and blockdoc all read flagship ADR-0112's `NodeManifestEntry` grammar. A node's name is its JSX tag.
   `laravel-beam-ux` ships a base profile of the intrinsic HTML elements, with open attributes.
   - **A placeable component is a `#[NodeType]` Block Data class.** Its properties are its props, and `category()` and
     `admitsChildCategories()` give its containment. The React component types its props from the generated TypeScript.
   - **A check keeps the JS side in step.** It compares the host's JS component registry with the profile, so the two
     can't drift silently.
   - **Component entries keep their inference.** `TsxPropInference` stays for them, since they have no class, and its
     schema stays a draft.

   *Amended by ADR-0231 decision 3 (2026-10-15): a placeable component is a `#[NodeType]` class or a component entry of
   the realm, read at its Working Head.*
7. **The host declares a profile per realm.** Central entries are edited against the HTML profile plus the host's
   components, and code is admitted. Hosted-site entries are edited against the profile the host offers tenants, marked
   `x-admits-code: false`. The save check and the inspector read the same profile. ADR-0227's blocks-only rule is that
   flag, and it has no second reader.
   *Amended by ADR-0231 decision 3 (2026-10-15): a profile also offers the realm's own component entries.*
8. **A save refuses only what it adds or changes.** `save-body` checks each node it adds or changes, compared by id
   against the Working Head. It looks for an unknown name, illegal containment, or a prop that fails its schema. The 422
   names the node's id and path.
   - **Untouched nodes don't block.** A violation in a node the save didn't touch is reported but not refused: as a
     diagnostic beside `compileError`, and by the doctor.
   - **A draft schema** checks types and enums only.
   - **Reading never runs the check** (ADR-0112's second rule).
9. **Source becomes blocks on the server.** Three paths bring source to a page: registration from disk, the Files action
   that brings disk back as a draft (ADR-0226), and a source write. Each runs the TSX Lens in a Node process (ADR-0209
   §7, the `NodeEntryBodyCompiler` precedent), reattaches ids, and stores a JsonDoc.
   - **Import and restore never refuse.** They store what they read and record diagnostics, because neither is
     authoring.
   - **A file with code outside its JSX isn't a page.** Importing it leaves the entry as it was and records a diagnostic.

   *Amended 2026-10-17 (EP-07a boundary fix, orch-lead ruling): "never refuse" covers profile legality; where
   `x-admits-code` is false, a restore of a version carrying code is refused and leaves the entry as it was (ADR-0227
   decision 1), and Publish refuses both.*

   *Amended by ADR-0233 decision 2 (2026-10-16): for an MDX page each path runs the MDX Lens, which lives beside the MDX
   kit in `@splicewire/beam-mdx`.*
10. **There is one input per kind.**
    - **Blocks:** `save-body` takes a declared JsonDoc.
    - **Source:** a source write, `{ source: string }`, serves every codec that edits text, and the codec encodes it. For
      a `tsx` page, decision 9 applies.
    - **Form:** a form save is checked against the entry's schema.

    Every new operation declares its `affordance:`.

## Consequences

- **`array_is_list` retires**, along with `JsonDocShape::is`. `AcceptsJsonDoc` comes to mean "this kind is blocks".
- **The generators need a union projection.** Spatie's TypeScript transformer and `schemastud/laravel-data-schemas` must
  project a `PropertyMorphableData` base as the union of its morph subclasses, and neither reads a morph today. A
  property typed as a PHP union already projects to `anyOf` (`laravel-data-schemas` `242349b`). This is the fifth need for
  the projection on record, and the first decided.
- **`Schemastud\Blockdoc\Contracts\ManifestNodeSource` gains a second contributor** at a host that already binds one: the
  flagship binds `splicewire/tower`'s `ContentBlockManifestSource`. How several sources compose follows
  `rushing/laravel-popcorn` registry-kernel's rulings on registries.
- **The lens is renamed in beam JS.** It becomes the **TSX Lens** at `@splicewire/beam-ux/tsx-lens`, and its type
  `BlockDoc` becomes `TsxTree`. The JsonDoc helpers move to `@splicewire/beam-ux/jsondoc`. The rename lands with phase 1
  of beam JS ADR-0005, the kit move, with no alias. The beam site's and audiostud's host copies, which import the old
  path, are deleted in its phase 2.
- **`EntryBodySaveOp`'s subtree-loss limitation becomes fixable.** With ids, a node the author couldn't see can be told
  apart from a node the author deleted.
- **Other editor-parity tickets build on this.** Ticket 12 (binding) declares a binding in the union and checks it against
  the profile; a binding is never an Opaque Island. Ticket 13 (components) starts from decision 6's component node.
  Ticket 15 (MDX) moves the MDX kit to `#[NodeType]` classes.

## Considered and not taken

- **A JSON Schema file that the Data class references.** That would be two declarations of one shape.
- **A component's node inferred from its TSX.** The server can't read every host's source, and an inferred schema carries
  no containment.
- **Nodes declared in TypeScript beside the component, with JSON emitted for the server.** The client would author the
  shape and the server consume it, the reverse of the estate's direction.
- **Refusing a violation anywhere in the document.** Removing a component from a profile would lock every page that uses
  it until someone edited the component out.
- **Reporting only, never refusing.** The declared shape would have no force on the one path an author controls.
- **A realm check in the operation beside the profile.** It would be a second reader of one rule.
- **Both `tsx` shapes kept, converted in the browser.** Ids and the save check would hold on one path only.
- **Source stored as the truth, with the JsonDoc derived on each read.** Ids couldn't persist.
