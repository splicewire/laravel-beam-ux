# EP-06 — Node identity, save strictness, source to blocks (PROOF)

ADRs: ux-0228 d3 (a stable id), d8 (save strictness), d9 (source to blocks via the Node TSX Lens). One
clean branch, **`slice/ep-06-d9-node-lens`**, history **d3 → 07a → d8 → d9** (07a is in the line because
d8/d9 build on its profile + legality; 07a is reviewed on its own branch and this rebases onto main once
07a lands).

## d3 — a block carries a stable UUIDv7 id

`JsonBlockNode` gains a nullable UUIDv7 `id`; the TSX printer leaves it out of source, and it round-trips.

## d8 — a save refuses only the nodes it adds or changes

`BodyNodeDiff::changedIds()` computes the ids a save adds or changes vs the Working Head **by content per
id** (signature = parent id + own content minus children), so a content change, a duplicated id, and a
node moved under a new parent all count; a node with no id is always checked. `ProfileLegality` gains a
`checkIds` filter that narrows **only** the legality band (unknown-name, containment, attrsSchema); the
ux-0227 d1 **code refusal** (opaque node + expression prop) stays whole-body — an unchanged opaque or
expression already in a tenant entry is still refused (ADR-0227 d1 is unconditional; decision 2's pack
re-assertion is a system write, outside the tenant-save check). The shared `CanvasBodyGuard` carries this
for every save path. Defensive (ADR-0233 d6): a source write on a tenant-resident entry is refused.

**The code-boundary choke (HIGH-1 review fix).** The guard runs at the publication CHOKE —
`EntryPublication::write` (which `recordDraft`, save-body and save-source all call) plus `restore` (which
guards the version snapshot's `payload` BEFORE applying it, refuse-before-write) — so **every AUTHOR and
API write** is covered in one place: save-body, save-source, save-draft (`EntryDraftSaveOp->recordDraft`)
and restore. This closed a real bypass: previously save-draft and restore wrote a canvas body without the
guard, so a tenant could smuggle an opaque node or an expression prop in via save-draft+publish or a
code-bearing restore, past `x-admits-code: false`. The per-op guard calls were removed; `CanvasBodyGuard`
now runs the JsonNode well-formedness pass and the profile/code check together. Red-first: a tenant
save-draft with an opaque node → 422; with an expression prop → 422; a code-bearing restore on a tenant
entry → 422 (`EntryPublicationTest`).

**The deliberate system-write exemption.** Disk registration, seeders and pack re-assertion write the body
through `Provenance\Reasserter` → the storage driver directly, NOT `EntryPublication::write`, so they are
NOT subject to the code boundary. This is intentional (orch-lead ruling 2026-10-17 01:25Z): they carry
content the host or a package SHIPS, which ux-0227 d1 is explicit holds the code, and ADR-0227 d2's
re-assertion is a system write outside the tenant-save check. The choke covers the author/API surface; the
Reasserter path is the sanctioned system exemption.

## d9 — source becomes blocks on the server (the Node TSX Lens)

`resources/lens/tsx-to-blocks.mjs` (mirrors `compile.mjs`, resolves the host's built
`@splicewire/beam-ux/blockdoc` `parse`+`toJson`) + `NodeTsxLens` (PHP shells to it; a lens failure → 422
on `source`). `JsonDocIdRematcher` is beam-native (content match → `similar_text` fallback for edited
text → a fresh UUIDv7; modeled on schemastud's `NodeIdRematcher` **precedent**, not its ProseMirror-shaped
code). `SourceToBlocks` composes them: lower, then reattach the Working Head's ids. **Real run, SHA-bound**
— see `d9-real-lens-run.md` (scratch beam `origin/main` `ea4abe99`, `dist/blockdoc.js` sha256 `5dd78f47…`,
all three id behaviours PASS).

## The three source→blocks writers (ux-0228 d9 names three)

1. **Registration from disk — WIRED.** `RegisterEntriesFromDisk::writeBody` lowers a Blocks-kind (tsx)
   file through the Lens (the "future structural-import mechanism" the method deferred to, ADR-0016); a
   file the Lens can't read falls back to raw source and is recorded in the batch failures. Test: a
   registered tsx page stores a lowered JsonDoc.
2. **The Files action — NOT BUILT YET.** No `Files` action that writes an entry body from a file exists
   in beam-ux ops, the operator console, Tower, or the flagship UI (searched 2026-10-17). The Files view
   with **"Take the file" / Re-mirror** — the path that imports a mirrored file's content back into an
   entry — is **EP-16 ("Git records what was published", Wave 5)**, not EP-06. When EP-16 builds it, "Take
   the file" MUST store through `SourceToBlocks` (the same lower + id-rematch), exactly as the other two
   writers do. Nominated in ticket 00.
3. **A source write — WIRED.** `EntrySourceSaveOp` on a Blocks-kind (tsx) entry now lowers the source
   through the Lens and stores the JsonDoc (the EP-05 refusal became the lowering); a Source-kind entry
   still encodes via its codec, a Form-kind entry still has no source editor, and the tenant-residency
   source guard stays first. Test: a tsx save-source stores a lowered JsonDoc; a Form-kind refuses.

## Tests

beam-ux Manifest + JsonDoc + EntryBodyOps + DiskEntriesPublication suites green (incl. BodyNodeDiff 5,
ProfileLegality narrowing 3, JsonDocIdRematcher 5, SourceToBlocks 2, the tsx save-source lowering, the
tsx disk-registration lowering, the two tenant-refusal Feature tests). The only full-suite reds remain
the pre-existing `FrameResourcesCollectorTest` set (verified identical at the base).
