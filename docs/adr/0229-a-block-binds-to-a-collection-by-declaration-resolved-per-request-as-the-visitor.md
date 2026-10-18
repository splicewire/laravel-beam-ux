# ADR-0229 — A block binds to a Collection by declaration, resolved per request as the visitor

Status: **accepted**. Decided in editor-parity ticket 12, 2026-10-15.
Date: 2026-10-15
Builds on: **ADR-0228** (the body is a declared `JsonNode` union checked against a Manifest Profile), **ADR-0227** (a
tenant-owned entry holds blocks, not code), **ADR-0212** (entry access is two conjunctive rights on the row),
**ADR-0209** (the public entry renderer), **ADR-0213** (chrome is inherited down the containment tree).
Amends: ADR-0228 decision 2 (a block's props have six kinds, not five). ADR-0209 §1 (a page's segment may be a
parameter). ADR-0213 §6 (a bound block gets its Records from the renderer and doesn't fetch them).
Companions: `splicewire/laravel-beam docs/adr/0230-a-guest-reads-a-resource-only-through-its-declared-public-read.md`
(what a guest may read), and beam JS `CONTEXT.md` (**Collection**, **Record**, **Repeat**, **Field Binding**, **Data
Condition**, **Record Page**, **Public Read**).

## Context

Nothing binds a block to data today. A prop is a literal or code: `PropKind` is `string`, `number`, `boolean`,
`expression` or `boolean-shorthand` (beam JS `beam-ux/src/blockdoc/types.ts:21`). Neither Studio compositions nor
`schemastud/laravel-blockdoc` has a binding. A list page is a code component that fetches its own data (ADR-0213 §6). A
tenant's Hosted site holds no code (ADR-0227 decision 1), so a tenant can't build a list page at all.

The pieces a binding needs already exist:

- **A query vocabulary.** A saved query is `filter`, `sort`, `include`, `limit` and `filterVariant`, validated against
  the resource's declared vocabulary (`splicewire/laravel-beam` `src/Filters/FilterQuerySelection.php:90`).
  `ParticleListQuery::forList` composes the backing, the scope, the selection, the includes and the default order
  (`src/Particle/ParticleListQuery.php:19`).
- **A per-request payload.** The renderer already sends per-viewer data beside a static artifact. It sends the
  artifact's address (`src/Http/Controllers/PublicEntryController.php:141`), the nav already gated for the viewer
  (`:158`), and the page props a package contributes (`:160`).
- **A read boundary per resource.** `ResourceReadGuard` decides a read for a named actor, and a null actor is a guest
  (`splicewire/laravel-beam` `src/Authorization/ResourceReadGuard.php:36-49`).

Nothing says which of a resource's rows and fields a guest may receive. The companion ADR-0230 decides that.

Webflow (Collection lists, Collection template pages, conditional visibility) and Bricks (Query Loop, dynamic data,
conditions) set the benchmark. Their record sets are made in the UI. Here a record set is a declared resource or an
entry's child pages, and a tenant-registered schema's records are editor-parity ticket 18's question.

## Decision

1. **A Collection is what the Manifest Profile offers for binding.** It is an entry's child pages or a particle resource.
   The profile lists each bindable Collection with its bindable fields as JSON Schema, and the inspector and a save read
   the same list (ADR-0228 decision 7). A Hosted-site profile lists only its own tenant's Collections. Whether a
   registered schema's records are a Collection is editor-parity ticket 18's to decide. No class is named bare
   `Collection`, which is Laravel's word, and `CollectionBacking` is already beam's.
   *Answered by ADR-0238 decision 1 (2026-10-16): on a Hosted site, a registered schema its author marks
   (`x-beam-collection`) is a Collection, and its Records are served by one resource (`splicewire/laravel-beam`
   ADR-0235).*
2. **A Repeat is a field on a block.** Any block can repeat, and the block itself is what repeats, once per Record. The
   Repeat holds its Collection, its query (decision 6) and its empty state (decision 9). The `JsonNode` union keeps its
   three arms. In `.tsx` the Repeat prints as a lens-owned object prop beside `bdStyle`: `bdRepeat`.
3. **A Field Binding is a sixth prop kind, `field`.** Its value is a path into the Record in scope, such as `cover.url`. A
   text node gains an optional `field` path, and its literal text becomes the placeholder for an empty field. A binding is
   never an expression, so a tenant can author one (ADR-0227). The save checks the path against the Collection's declared
   fields.
   *EP-22 amendment (owner ruling, 2026-10-18): the TSX Lens writes a prop binding as
   `title={bdField("title")}` and bound text as `{bdText("title", "Untitled")}`. These are
   structurally recognized declarations, not executable author functions. Every argument must be a
   literal string; identifiers, templates, calls, expressions and spreads refuse at Lens read and save.
   `bdText`'s second argument is the literal fallback. The compiled artifact consumes the request's
   binding payload through the package runtime; it does not execute these source helpers or fetch Records.*
4. **Records are resolved on the server, per request, as the visitor.** The public renderer reads each Repeat's Records
   and the Record Page's Record, and sends them in the page payload as it sends the nav. The artifact stays static and is
   compiled from the body as before. Only bindings declared in the published body run, so the site never answers an
   arbitrary query. The canvas uses the same resolver (decision 10).
   *Amended by ADR-0232 decision 6 (2026-10-15): in a Preview, the bindings declared in the previewed versions run in
   place of the published body's, still read as the preview's viewer.*
5. **What a visitor may read:**
   - **Child pages** are read under ADR-0212 and the publish gate, as the nav reads them.
   - **A resource's Records** are read as the visitor, through the resource's own read guard, never with the author's or
     the publisher's rights.
   - **A guest** reads a resource's Records only through its declared Public Read (ADR-0230). Without one, the Repeat
     renders its empty state and the editor warns (decision 10).
   - **The server sends only the fields the page uses**: those its Field Bindings, Data Conditions and Record links name.
     The resource's owner decides what may ever be public, and a site author can only narrow it.
6. **A Repeat's query is a saved query:** `filter`, `sort`, `limit` and `filterVariant`, checked against the Collection's
   declared vocabulary when the body is saved. Includes aren't authored; the server derives them from the bound field
   paths. A filter value is a literal or a field of the Record in scope. A hand-picked list is a filter on ids.
   - **Paging** is a URL parameter per Repeat. Pages are numbered, and "load more" is the same read through a partial
     reload.
   - **Filters a visitor sets**, such as a shopper choosing a category, are deferred.

   *Amended by ADR-0231 decision 13 (2026-10-15): inside an Authored Component, a filter value may also be one of its
   props.*
7. **A nested Repeat walks a relation of the outer Record**: a to-many field its Collection declares, loaded in one batch
   for every outer Record. A query that filters on the Record in scope is allowed only where exactly one Record is in
   scope, on a Record Page ("posts by this author"). The server never runs a query per row, so a page's query count grows
   with its Repeats, not its Records.
8. **A Record Page is a page entry whose segment is a parameter** (`{slug}`), bound to a Collection by one of its unique
   fields.
   - **Resolution.** Under one parent, literal children are tried first and the parameter child last. A parent has at
     most one parameter child.
   - **The Record** is read as the visitor (decision 5). No Record gives the uniform 404 (ADR-0209 §5).
   - **The page reads it.** Field Bindings, the page's title and its SEO fields read the Record.
   - **The sitemap** lists one URL for each Record a guest may read.
   - **`page` stays the sole routable type**, and chrome is inherited as for any page (ADR-0213 §4).
   - **One per Collection per site.** A save that adds a second Record Page for the same Collection is refused.

   *Amended by ADR-0236 decisions 2, 3 and 9 (2026-10-16): with no Record, a Redirect at the URL answers before the
   uniform 404. A Record whose bound field changes leaves a Redirect from its old URL to the Record, and a Record Page
   that moves leaves one that keeps its parameter.*
9. **The Repeat owns its empty state:** a subtree rendered in its place when there are no Records, because none exist,
   the filters match none, or the visitor may read none. A toggle on the Repeat shows it in the canvas for editing. It
   is part of the Repeat's field and prints inside `bdRepeat`.
10. **The canvas shows live Records, read as the author.** Bound blocks render the first page of Records through the
    same resolver. A Repeat on a public page whose Collection has no Public Read gets a warning: visitors will see its
    empty state. A Record Page opens on one Record, with a picker to switch. Seeing a page exactly as a visitor does is
    the Release preview's job (editor-parity ticket 14).
11. **Each Record carries a computed `url` field** that the server supplies: a child page's own URL, or a resource
    Record's URL under its Collection's Record Page. Decision 8's one-per-site rule makes that URL canonical. A link
    bound to `url` on a Collection with no Record Page is reported at save.
12. **A Data Condition is a `when` field on a block.** Its rules are field, operator and value, joined by *all* or *any*
    and checked against the Record in scope. The operators are: is set, is empty, equals, does not equal, greater than
    and less than (numbers and dates), and contains (text and lists). The browser evaluates it over the Records it was
    already sent. It shapes what a reader sees and never decides what they may read; that is the View gate's job. In
    `.tsx` it prints beside `bdStyle`, as `bdWhen`.
    *Amended by ADR-0231 decision 13 (2026-10-15): inside an Authored Component, a rule may also test one of its
    props.*
13. **A plain text node takes plain-text fields only.** A rich field is Markdown or a `@schemastud/blockdoc` document,
    never MDX, because MDX is code. It binds to the content prop of a component that builds elements from the field's
    declared format and never inserts raw HTML. The field's schema names the format.

## Consequences

- **ADR-0228's union gains fields, not arms.** Its decision 2 now gives a block's props six kinds, with `field` added. A
  block gains `repeat` and `when` beside `style`, and a text node gains `field`. The printer and the TSX Lens learn
  `bdRepeat` and `bdWhen`, and the generated TS and JSON Schema carry the new fields.
- **ADR-0209 §1 learns a parameter segment.** The walk tries the literal children first, then the one parameter child,
  and reads that child's Record as the visitor before the body is read. §10's unique `(parent_id, segment)` still holds,
  and "at most one parameter child per parent" is checked at save.
- **ADR-0213 §6 is narrowed, not withdrawn.** A code component still fetches its own data, on central entries where code
  is admitted. A bound block gets its Records from the renderer. §6 rejected a `props_ref` naming a server-side resolver,
  because it would reinvent controllers as data and need its own gating, versioning and invalidation. A binding names no
  resolver: it names a declared Collection and a query in that Collection's declared vocabulary. It is gated by the
  visitor's own read, versioned with the body, and read per request, so there is nothing to invalidate.
- **Caching.** The artifact keeps its address and its caching (ADR-0209 §7 and its amendments). The page response now
  carries Records read for this visitor, so it can be shared between visitors only where every Record came through a
  Public Read.
- **The page payload** gains each Repeat's Records and the Record Page's Record beside `nav`, read through
  `ParticleListQuery` with the saved query applied.
- **The sitemap.** `EntrySitemapSource` (`src/Sitemap/EntrySitemapSource.php`) expands a Record Page into one URL for each
  Record a guest may read.
- **New work in beam JS.** The binding inspector (Collection, query and field pickers fed by the profile's JSON Schema,
  the empty-state toggle, the Record picker), the Data Condition editor, and the rich-field component. The canvas's
  `MdxView` isn't reused for rich fields: it is a host-supplied MDX island (`beam-ux/src/canvas/context.tsx:13,33`), and
  MDX read in a visitor's browser would be compiled there (ADR-0209 §7). `Prose` (`beam-ux/src/site/Prose.tsx`) only
  sets typography.
- **SEO.** Record Pages raise the stakes of ADR-0209's client rendering, because a Record Page is usually the page a
  search engine should index. Server-side rendering stays a host decision.
- **The View gate is the security boundary, and the public path doesn't apply it yet.** `ViewGateFilter`
  (`src/Canvas/ViewGateFilter.php:11`, "the REAL security boundary") runs only on the editor's read
  (`src/Particle/EntryBodyEnvelope.php:30`), and the public artifact is printed from the whole body
  (`src/Compile/CompileEntryBody.php:136`). This defect is routed to launch ticket 00. A Data Condition is not its fix.
- **Registered schemas wait on editor-parity ticket 18.** A Collection's fields reach the editor as JSON Schema however
  that ticket decides, so nothing here waits on it.
  *Decided by ADR-0238 (2026-10-16): the fields are the marked schema's latest version, and a binding follows the renames
  a later version declares.*

## Considered and not taken

- **The browser fetches Records from a query endpoint after load.** It adds a round trip and puts a public query API on
  the site's origin.
- **Records resolved at publish and baked into the artifact.** They go stale, and they are read with the publisher's
  rights instead of the visitor's.
- **A `repeat` node arm wrapping the repeated subtree**, like Webflow's Collection List. It adds an arm that the
  containment check has to look through.
- **A `bind` map on the block, or a `<Field>` child component.** Both put the binding somewhere other than the prop or
  text it fills, so the inspector can't show it in place.
- **The read guard alone deciding guest reads, or reading with the publisher's rights.** The first leaves what is public
  to whichever policy a resource happens to bind. The second publishes whatever its author can see.
- **Each Record as its own entry.** It duplicates rows that then have to be kept in step.
- **A public path the resource declares outside the containment tree.** It breaks "the URL is the containment"
  (ADR-0209, ADR-0213).
- **An inner query per outer Record, with a cap.** The query count would grow with the Records on the page.
- **An empty state as a sibling block whose Data Condition tests the Repeat's count.** The condition would have to name
  the Repeat by id, and printed source carries no ids (ADR-0228 decision 3).
- **The canvas reading as a guest, or showing sample Records.** A gated page's author would see less than its readers get,
  and samples lose what-you-see editing.
- **Several Record Pages per Collection**, the author picking one for each link. A Record would have no canonical URL for
  the sitemap or a search engine.
- **Rich fields rendered to HTML on the server and inserted.** Text that a tenant's own users write would reach a public
  page as raw HTML.
