# ADR-0233 — An MDX page holds blocks, and MDX is only its file language

Status: **accepted**. Decided in editor-parity ticket 15, 2026-10-16.
Date: 2026-10-16
Builds on: **ADR-0228** (the entry declares its body's kind; blocks are checked against a Manifest Profile; source
becomes blocks on the server), **ADR-0229** (a block binds to a Collection), **ADR-0231** (Authored Components, props and
slots), **ADR-0232** (a version holds what a guest sees), **ADR-0226** (a Release publishes entries together), **ADR-0225**
(one history), **ADR-0227** (a tenant-owned entry holds blocks, not code), **ADR-0213** (packages ship pages), **ADR-0215**
(an imported entry re-asserts from its origin while pristine), **ADR-0209 §7 and §12** (Node runs where content changes;
the build-time MDX bundle retires as hosts convert).
Amends: ADR-0228 decisions 1 and 9. ADR-0232 decision 1. ADR-0227 decision 1. ADR-0231 decisions 8 and 10 gain an MDX
form. Each carries a note pointing here.
Companions: beam JS `docs/adr/0006-text-is-edited-in-prose-runs-through-blockdoc-and-source-mode-is-a-view-of-the-local-session.md`
(how the editor edits these pages), beam JS `docs/adr/0005-one-editor-the-host-kit-lives-in-beam-ux-and-a-host-conforms-by-mounting-it.md`
(amended there), and beam JS `CONTEXT.md` (**MDX Lens**, **File Language**, **Page Field**, **Prose Run**, **Source
Mode**; **JsonDoc** and **Opaque Island** amended).

## Context

ADR-0228 gave a `tsx` page a body of blocks and left what an MDX page holds to editor-parity ticket 15, "until then it
holds source". Most site content is MDX: 43 of the flagship's 44 central entries, 24 of the beam site's 27 mirror files,
and all of numero's, the five MDX satellites' and schemastud's content.

Measured 2026-10-15 and 2026-10-16 over 86 MDX files on disk at nine hosts and 72 stored MDX bodies (the flagship 43,
the beam site 26, `laravel-beam-starter` 3, all of them pages):

- **Almost none of it is code.** 75 of the 86 files and 66 of the 72 stored bodies hold no code outside their JSX. Most
  of the rest is JSX passed as a prop, a literal value in braces, schemastud's `export const meta`, or a comment. Three
  pieces are real code.
- **The rich MDX editor can't keep identity.** `@splicewire/beam-mdx/editor`'s `MdxDocumentEditor` edits Markdown text
  through mdxeditor, so a node has no id that survives an edit. A round trip through it changed 99 of 158 bodies.
- **Canonical printing settles.** Printed once from the Markdown tree, 150 of 158 bodies print the same a second time.
  All 8 that don't hold code, and the other 9 of the 17 bodies that hold code settle.
- **Frontmatter carries more than page settings.** Besides title, layout and the nav keys, essays carry `topic`,
  `readingTime`, `thread`, `stage` and `audience` (9 of stephenrushing's 12 files), and its build-time listing reads them.
- **The MDX kit's vocabulary is a local copy.** `KIT_MANIFEST` mirrors the Block Manifest's shape in TypeScript
  (`@splicewire/beam-mdx` `src/editor/manifest.ts:1-14`), which ADR-0228 decision 6 already retired in principle.
- **Fragments are copies.** All 36 `<Content name="…">` tags on disk name one of 9 fragments with no other attribute.
  The four legal fragments are one paragraph each, copied into 4 or 5 hosts, and with frontmatter stripped every copy
  matches its fragment's text exactly.
- **A second write path bypasses the entry.** `/beam/content` reads and writes raw `.mdx` files at the flagship
  (`~/Herd/splicewire-app/routes/tenant.php:899-904`, `ContentAuthoringController`, `Mdx::write`), and its client is an
  unmounted cluster (`resources/js/app/shell/contentEdit.tsx:12-17`).

## Decision

1. **An MDX page holds blocks.** MDX names a page's **File Language**, not its Body Kind. A page holds a JsonDoc whether
   its file is TSX or MDX, and it is checked against its realm's Manifest Profile exactly as a `tsx` page is (ADR-0228
   decisions 6 to 8). An MDX page binds (ADR-0229) and places Authored Components (ADR-0231) as a `tsx` page does. This
   amends ADR-0228 decision 1, whose "until then it holds source" ends here.
2. **The MDX Lens reads MDX into blocks, and a canonical printer writes blocks back as MDX.**
   - **Reading.** Markdown is read as the HTML profile's own nodes (`p`, `h2`, `ul`, `li`, `blockquote`, `pre`,
     `strong`, `em`, `code`, `a`, `img`, `hr`, `br` and the table elements), and JSX as components. A soft line break
     inside a paragraph becomes a space. JSX passed as a prop becomes a named slot, and a literal value in braces a plain
     prop. A comment, and any other expression, becomes an Opaque Island, legal on central entries only (ADR-0228
     decision 5). A file with code outside its JSX beyond schemastud's `meta` (decision 9) isn't a page (ADR-0228
     decision 9).
   - **Where it runs.** The lens lives in `@splicewire/beam-mdx` beside the kit and runs in a Node process on the server
     through `splicewire/laravel-beam-mdx` (ADR-0209 §7), on ADR-0228 decision 9's three paths. Ids are reattached
     against the Working Head by content, as for the TSX Lens (ADR-0228 decision 3).
   - **Printing.** A block that Markdown can express prints as Markdown, one line per paragraph. Anything else prints as
     JSX: a component, a prop Markdown has no syntax for, and the lens-owned fields, which print as the same attributes
     as in TSX (`bdStyle`, `bdRepeat`, `bdWhen`; ADR-0228 decision 4, ADR-0229 decisions 2 and 12). The mirror and every
     compile read the printer's output.
3. **Frontmatter isn't part of the body.** Its keys are a page's settings, its SEO fields and its Page Fields. The
   version holds them (ADR-0232 decision 1), and the editor's page settings edit them for TSX and MDX pages alike. The
   mirror prints them as frontmatter, and import reads them back. A key nothing declares is kept, shown in page settings
   and reported by the doctor (`UnclaimedFrontmatterKeyAudit`). `splicewire:beam:ux:enrich-page-schemas` retires.
4. **A page's parent declares the Page Fields its child pages carry.** One declaration serves three readers: the Record
   fields of the child-page Collection (ADR-0229 decision 1), the fields page settings shows on each child, and the save
   check. A central or satellite host declares a Data class that the parent names. How a Hosted site declares one
   follows editor-parity ticket 18. A section whose pages carry fields gets a page of its own, so the fields have a
   declarer and the section is a Collection.
   *Amended by ADR-0238 decision 3 (2026-10-16): a Hosted site's parent names one of its tenant's registered schemas,
   chosen in its page settings and held in its version.*
5. **A File Language is chosen at creation and can change.**
   - It defaults to the parent's, then to the host's default. Page settings shows it and changes it, and the version
     holds it, which adds it to ADR-0232 decision 1's list. Publish moves the mirror file, as it does for a URL change.
   - Every blocks entry that has a file has one, not only pages. In MDX a Prop Reference prints as `{props.title}` and a
     Slot as `{props.children}` or `{props.media ?? <…/>}`, and the MDX Lens reads them back as the TSX Lens reads
     `{title}` and `{children}` (ADR-0231 decisions 8 and 10).
   - What a page looks like comes from its blocks and its layout, never its File Language. Chrome that an MDX renderer
     adds today, such as `ContentShow`'s prose wrapper, eyebrow and Receipts list, becomes a layout's when its host
     converts.
6. **A Hosted site's pages have no File Language.** A tenant's page has no file and no Source Mode, and Markdown pasted
   into it becomes blocks. ADR-0227 decision 1's refusal of "an MDX expression" has nothing left to refuse, and the
   profile's `x-admits-code: false` stays the rule's one reader (ADR-0228 decision 7). Source Mode for tenants is
   deferred.
7. **The MDX kit is declared on the server.** Its components become `#[NodeType]` Block Data classes in
   `splicewire/laravel-beam-mdx`, beside `@splicewire/beam-mdx/kit`, as Code Components, and `KIT_MANIFEST` retires.
   Every other component is declared by whoever ships it. `laravel-beam-mdx` gains `schemastud/laravel-blockdoc` (MIT).
8. **Fragments become Authored Components.** A fragment is placed by its tag (`<LegalEngine />`), and `Content` leaves
   the kit.
   - The four legal texts ship once, from a package, as package-origin component entries (ADR-0213), re-asserted while
     pristine (ADR-0215), so a site can still edit its copy. Which package ships them is a build choice.
   - numero's reading disclaimer becomes numero's own Authored Component, and the beam site's three fragment page
     entries become component entries.
   - A fragment no page includes, such as entreport's `fragments/advisory.mdx`, is reported, not converted.
9. **Each host converts once, at adoption.**
   - **Files.** A command reads every MDX file through the lens and prints it canonically, converting schemastud's
     `export const meta` to frontmatter, in one reviewed commit. A package runs it over the pages it ships.
   - **Stored pages.** Each stored MDX page gets a new version holding blocks, and they publish together as one Release,
     previewed first (ADR-0232). Where the host mirrors, that Release's commit is the reviewed commit. A page with a
     pending draft has its published version converted and published, and its draft converted on top. Older versions
     stay as they are, and Restore reads them through the lens (ADR-0228 decision 9).
   - **Safety.** The command renders each page before and after, and leaves out and reports any page whose output
     changes.
   - **Readers.** Before the rewrite, both frontmatter readers accept both key spellings: the PHP grammar canonicalizes
     to snake case, and `@splicewire/beam-mdx` reads camelCase at build time.
10. **The raw-file write path retires.** `/beam/content`'s routes, `ContentAuthoringController`, `Mdx::write`,
    `@splicewire/beam-mdx`'s `loadRawMdx`, `saveMdx` and `putMdx`, the flagship's unmounted content-edit cluster, and the
    `content.author` permission with its test. An MDX page is written through the entry operations like any other page
    (ADR-0214, ADR-0225).

## Consequences

- **ADR-0228's three paths gain a second lens.** Registration from disk, the Files action and a source write run the MDX
  Lens for an MDX page and the TSX Lens for a `tsx` page. The source write stays the input for source-kind entries and
  for those paths; the editor's Source Mode doesn't use it (beam JS ADR-0006).
- **The canvas opens MDX pages.** The surface follows the Body Kind, and the canvas's `Mdx` island retires (beam JS
  ADR-0006, which amends beam JS ADR-0005 decision 3).
- **blockdoc's node names follow ADR-0228 decision 6.** Its base manifest (`paragraph`, `bullet_list`, …) is renamed to
  JSX tags, and the rich-content slots Tower has stored migrate with it.
- **The build-time MDX bundle retires with each host's conversion** (ADR-0209 §12). numero and the five MDX satellites
  convert before they adopt the editor (beam JS ADR-0005). calcucrypt's guides are Post records synced from MDX, not
  entries, so whether they become pages is part of its conversion.
- **Release review covers the conversion.** Formatting is reviewed once, separately from content, so every later diff
  and History entry shows only what an author changed.

## Considered and not taken

- **MDX pages hold source, edited in `MdxDocumentEditor`.** Ids can't persist in Markdown text, so bindings, Block
  Styles, the save check by id and Authored Components would hold for `tsx` pages only.
- **Frontmatter as part of the body.** Page settings would edit two shapes, one per language, and Preview's version
  fields (ADR-0232) would have a second home.
- **Page Fields declared once per host, or per template.** A host-wide declaration shows essay fields on every page, and
  a template's drops a page's fields whenever the page changes template.
- **`Content` kept as a Code Component that includes an entry by name.** An include with no props is an Authored
  Component already, and the legal texts would stay 19 copies.
- **Converting lazily, at each page's first Publish.** Its first real edit would carry the reformatting, in the mirror
  diff and in History.
- **A fixed File Language, or `tsx` for every page made in the editor.** A new page under an MDX section would change
  language, or couldn't.
