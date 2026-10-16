# ADR-0227 — A tenant-owned entry holds blocks, not code, and starts as a pack copy

Status: **accepted**. Decided in editor-parity ticket 08, 2026-10-14.
Date: 2026-10-14
Builds on: **ADR-0215** (an entry records its origin and re-asserts from it while pristine), **ADR-0209** (the public
renderer), **ADR-0226 §7** (a tenant gets Releases, never commits).
Amends: ADR-0215 §1, which listed three origins. ADR-0209 §3, which didn't say whose tree the `site` walk reads.
Companion: `splicewire/tower docs/adr/0008-a-tenants-site-is-a-hosted-site-served-from-its-own-schema-on-its-own-origin.md`
(what a Hosted site is, where it's served, and who may have and edit one).

## Context

Tower ADR-0008 lets a tenant edit its own **Hosted site** with the shared editor. The rows live in the tenant's schema,
and the starter pages arrive as copies from the source tenant's own Hosted site through a Scaffold Pack.

The operator is trusted with code; a tenant author is not. A page body is code. The JSON body is a JSX tree whose props
can be `expression`s (`@splicewire/beam-ux` `src/blockdoc/json.ts:28-38`) and whose `opaque` nodes carry verbatim
source (`:62-67`).
MDX bodies run expressions too, and 43 of the flagship's 44 central pages are MDX (measured 2026-10-14). Publishing
compiles the body into a script that every reader's browser runs (`src/Compile/EntryArtifactStore.php`).

`ThemeResolver` merges package default → central theme row → tenant theme row (`src/Theme/ThemeResolver.php:13-17`).
Applied to a Hosted site, restyling the central site would restyle every tenant site that left a token alone.

## Decision

1. **A tenant-owned entry holds blocks, not code.** Saving an entry in a tenant's schema refuses an `expression` prop,
   an `opaque` node and an MDX expression, and the refusal names the node. A tenant author places registered components
   and edits text and literal props. Code lives in components the host or a package ships, and a tenant that needs its
   own code has a Connected site. A tenant's own components are block compositions, never TSX source. Central entries
   are unchanged.
   *Amended by ADR-0233 decision 6 (2026-10-16): a Hosted site's pages have no File Language, so no MDX reaches a
   tenant's save and the MDX-expression clause has nothing left to refuse. The profile flag stays the rule's one reader
   (ADR-0228 decision 7).*
2. **A pack copy carries `origin = pack:<slug>` and re-asserts while pristine.** This is ADR-0215 §1's fourth origin,
   and §2 applies to it unchanged. Re-applying the pack rewrites a copy whose body and title still hash to
   `asserted_hash`, and it never overwrites an edited copy. The divergence audit reports an edited copy whose pack source
   has since changed, and it names the pack. Placement is never re-asserted.
   *Extended 2026-10-16 (`splicewire/tower docs/adr/0009-a-starter-sites-schemas-travel-re-minted-on-each-new-tenants-own-origin-with-their-sample-records.md`
   decisions 6 and 7): the registered schemas a pack carries with a starter site re-assert while pristine, a schema
   staying pristine while its latest version is a pack copy, and so do its sample Records. A Record the tenant deleted is
   never re-created.*
3. **A Hosted site's theme skips the central tier.** On a site domain, the `site` slice resolves package default → the
   tenant's theme row, which is itself a pack copy. The central row keeps applying to the app's `shell` and `canvas`
   slices. Restyling the central site never restyles a tenant's site.
4. **The renderer reads the tree of the domain's owner.** ADR-0209 §3's walk of the `site` realm reads central rows on a
   central domain and the tenant's rows on a site domain. The artifact stream (`artifact_root`) resolves the same
   connection.

## Consequences

- The blocks-only check is the Hosted-site profile's `x-admits-code: false`, which `EntryBodySaveOp` and the editor's
  inspector both read. The inspector therefore never offers an expression prop that the save would refuse (amended by
  ADR-0228 decision 7, 2026-10-15).
- ADR-0215's precedence list (§3) is unchanged. A pack copy is a seeded row, below a CMS-edited row.
- Editor-parity tickets 12 (binding) and 13 (components) build within decision 1: a binding and a slot are declarations,
  not code.

## Considered and not taken

- **Full authoring for tenants**, the same as the operator. It is safe only if the site never shares an origin with
  anything a visitor is signed into, and a component that renders a prop as HTML reopens the hole regardless.
- **Code behind an ability a tenant's Owner grants.** It reopens the hole for every tenant whose Owner turns it on.
- **Copy once, never refreshed.** A fix to a pack's starter pages would never reach a tenant who hadn't touched them.
