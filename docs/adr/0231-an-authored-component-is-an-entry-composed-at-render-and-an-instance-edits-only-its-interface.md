# ADR-0231 — An Authored Component is an entry composed at render, and an instance edits only its interface

Status: **accepted**. Decided in editor-parity ticket 13, 2026-10-15.
Date: 2026-10-15
Builds on: **ADR-0228** (the body is declared by kind, and a placeable component is a manifest node), **ADR-0229** (a
block binds to a Collection by declaration), **ADR-0227** (a tenant-owned entry holds blocks, not code), **ADR-0213**
(chrome is composed at render), **ADR-0225** (Working Head and Publication Pin), **ADR-0226** (a Release publishes
entries together), **ADR-0212** (the read rights).
Amends: ADR-0228 decisions 1, 2, 6 and 7. ADR-0229 decisions 6 and 12. ADR-0213 §7. Each carries a note pointing here.
Cites, without editing: `splicewire/splicewire-app docs/adr/0112-ship-blockdoc-as-is-defer-the-open-standard-our-contribution-is-a-schema-grammar-contract-layer-over-any-block-serialization-not-a-competing-block-format.md`
(the Block Manifest is a grammar over any serialization), and `rushing/audiostud` editor-promotion ticket 08 (the deferred
`WriteTarget` resolver, answered by decision 7).
Companions: beam JS `docs/adr/0003-a-style-is-a-block-style-compiled-at-render-time.md` (its deferred variants take
decision 18's shape), beam JS `docs/adr/0004-editor-undo-is-a-local-session-and-history-is-server-versions.md` (one Local
Session per entry), and beam JS `CONTEXT.md` (**Code Component**, **Authored Component**, **Instance**, **Slot**, **Prop
Reference**, **Detach**).

## Context

Every component instance in the estate is a sealed, whole section. There are 22: 7 on the beam site's source page, 6 in
audiostud's editor seeds and 9 in the starter seeds. None has a prop or a child. Nothing below that ceiling has been
needed yet, and nothing above it exists:

- **The canvas renders a component sealed** and never passes it children (beam JS `beam-ux/src/canvas/CanvasNode.tsx:228-236`,
  `canvas/TreeRender.tsx:44-49`). The inspector edits its props as free-text strings with no schema
  (`canvas/attrsSchema.ts:80-101`).
- **The TSX Lens reads a JSX-valued attribute as an expression** (`beam-ux/src/blockdoc/lens.ts:167-168`). React's way
  of passing a named slot (`media={<img />}`) is therefore code, and a tenant's page refuses code.
- **The Block Manifest gives a node one child list** (`admitsChildCategories`) and no named slots (`@schemastud/blockdoc`
  `src/core/types.ts:66-109`).
- **Component entries exist, but nothing places one.** `UxType::Component` has draft prop inference
  (`TsxPropInference`), and four hosts store 0 component, layout or template rows among 113 entries. Only pages compile
  (`src/Compile/CompileEntryBody.php:86-89`), and the artifact route answers 404 for anything else
  (`src/Http/Controllers/EntryArtifactController.php:51`).
- **Layouts and templates already compose at render.** The renderer sends a chrome entry's artifact address beside the
  page's (`src/Http/Controllers/PublicEntryController.php:150-153`), and the client nests it (beam JS
  `beam-ux/src/docs/ArtifactChrome.tsx:26-41`). An MDX layout marks its hole with `{props.children}`
  (`ArtifactChrome.tsx:11`), which a blocks body can't write without code.

A tenant's own components are block compositions, never TSX (ADR-0227 decision 1). Beam JS ADR-0003 turned down a class
manager because shared styles come from components and tokens, so components carry "change it once" for the whole
editor. audiostud's editor-promotion ticket 08 deferred a `WriteTarget` resolver with four outcomes: `sealed`,
`instance-override`, `eject-to-local-copy` and `write-package-source`.

The benchmarks: Claude Design's instances take props only, and Detach is refused for code components. Webflow and Bricks
have props, slots, variants and instance overrides.

## Decision

### What a component is

1. **There are two kinds of component.**
   - A **Code Component** is code a host or a package ships, declared as a `#[NodeType]` class (ADR-0228 decision 6).
     An author places and configures it but never edits its inside.
   - An **Authored Component** is a `component` entry whose body kind is blocks. Its blocks and its declared interface
     (its props and slots) are versioned together, so a published version always carries the interface its instances
     were checked against. It is the only kind a tenant makes.
   - A central `component` entry may hold source instead, with its interface drafted by `TsxPropInference`. It is placed
     the same way.

   This amends ADR-0228 decision 1, which said a component holds source.
2. **A component's name is its JSX tag.** An Authored Component declares a PascalCase component name, defaulting from
   the entry's title and unique in its realm across Code and Authored Components. A clash is refused when the component
   is saved.
   - **Renaming.** The title can change freely. The name can't change while any entry's Working Head or Publication Pin
     uses it, and a rename that rewrites every instance is deferred.
   - **A later clash.** If a host later registers a Code Component with the same name, the registered one wins (ADR-0213
     §7's order), and the doctor names the shadowed entry.
3. **A realm's component entries join its Manifest Profile,** read at their Working Head, with their name, props, slots
   and root category. A placeable component is therefore a `#[NodeType]` class or a component entry, which amends
   ADR-0228 decisions 6 and 7. A tenant's site places its own realm's Authored Components and the Code Components its
   host offers. A central Authored Component reaches a tenant only as a Scaffold Pack copy (ADR-0227 decision 2), never
   by reference, so a central edit never changes a tenant's site.
4. **A component entry lives in its realm's containment tree, without a URL segment.** Only pages route (ADR-0209), so it
   has no URL and no nav entry. "Make a component" places it under the realm root, and an author may move it under a
   pass-through node to group components and share their rights.
   - **Reading.** A reader sees an instance only if they pass ADR-0212's rights on the component entry, as a chrome
     entry's artifact already requires (`EntryArtifactController.php:48-50`). Otherwise the instance renders nothing for
     them.
   - **Editing.** ADR-0212's rights are read rights. Editing a component needs the ability that edits any entry in its
     realm: `ux.author` on the central site, the save's declared ability (`src/Particle/EntryBodySaveOp.php:82`), and
     the tenant permission `site.edit` on a Hosted site (`splicewire/tower` ADR-0008). An Edit gate on the component's
     root block narrows it, and the canvas is the only place that gate is enforced (`src/Canvas/ViewGateFilter.php:8-12`).
   - **The entries list** shows components as their own group, with a use count for each.

### Instances

5. **An instance is edited only through its component's interface.** It holds its prop values, its slot content and its
   own block fields: a Block Style on the component's root element, a Repeat, a Data Condition and the gates (ADR-0228
   decision 4, ADR-0229 decisions 2 and 12). All of it is stored on the instance node. Nothing inside a component is
   overridden per instance. The inside changes by editing the component, which changes every instance, or by Detach
   (decision 17). A Code Component takes root style only if it forwards the style's class to its root element. The
   manifest marks those that do, and the inspector hides the style panels for the rest.
6. **The inspector's Props section is rendered from the node's props schema.** Each prop takes a literal, with a widget
   for its type, or a Field Binding where a Record is in scope (ADR-0229 decision 3).
   - **Declared props only.** An instance shows no free-text attribute rows and no `className`, since the panels never
     write a class (beam JS ADR-0003). A draft schema, a source component's, stays open and checks types and enums only.
   - **Expression props** exist on central entries only, and show read-only.
   - **An unset prop** renders the component's default. A required prop left unset is refused when the save adds or
     changes that instance (ADR-0228 decision 8).
7. **WriteTarget is ruled.**
   - **`sealed`:** a Code Component's inside, always, and an Authored Component's inside for an author who can't edit it
     (decision 4).
   - **`instance-override`:** decision 5, stored inline on the instance node.
   - **`eject-to-local-copy`:** Detach, for an Authored Component (decision 17). For a Code Component it is deferred,
     with its open questions on reachability, drift and provenance.
   - **`write-package-source`:** out of scope. The canvas never writes package source.

   No resolver is built. Where an edit lands follows from what it touches (the instance node or the component entry) and
   from whether the author can edit that.

### Props and slots

8. **An Authored Component declares its props in its body.** Each prop has a name, a type, a default, and whether it is
   required. The types are text, number, toggle, choice, link and image. Rich content goes into a slot.
   - **A Prop Reference** is how a block inside the component uses a prop: a seventh prop kind, `prop`, whose value is
     the prop's name, and an optional `prop` on a text node, as ADR-0229 added `field`. The save checks that the prop
     exists and that its type fits where it is used. This amends ADR-0228 decision 2.
   - **In printed TSX** the component destructures its props, and a reference prints as a plain identifier
     (`<h3>{title}</h3>`). The TSX Lens reads an identifier that names a declared prop as a Prop Reference, not as an
     Opaque Island.
     *Extended by ADR-0233 decision 5 (2026-10-16): in printed MDX a reference prints as `{props.title}`, and the MDX
     Lens reads it back the same way.*
   - **One root block.** It carries the instance's own fields, and its category decides where an instance can be placed.
9. **A component has a default slot and named slots.**
   - **The default slot is `children`,** declared by `admitsChildCategories()` as today. `[]` declares none.
   - **Named slots** are declared by the component, each with the categories it admits. The manifest node gains
     `slots: [{ name, admitsChildCategories }]`, an extension of flagship ADR-0112's grammar in `@schemastud/blockdoc`
     and `schemastud/laravel-blockdoc`.
   - **Storage.** An instance stores named slot content in a `slots` field (`{ media: JsonNode[] }`) beside `children`.
     The `JsonNode` union keeps its three arms.
   - **In TSX** a named slot prints as a JSX-valued attribute (`media={<img … />}`), so a Code Component receives a typed
     `ReactNode` prop, and the TSX Lens reads such an attribute as a slot. A source component's draft interface reads
     `children` as its default slot and a `ReactNode`-typed prop as a named slot.
   - **The save** checks each slot's content against the categories it admits, for the nodes it adds or changes.
   - **On the canvas** an instance renders its real component, with its slot content passed in as editable subtrees.
     Clicking the component's own markup selects the instance, and its slot regions take drops.
10. **An Authored Component declares a slot by placing a Slot node.** An unnamed Slot is the default slot, and a named
    Slot is a named slot. Decision 9's `slots` and the node's `admitsChildCategories` are derived from them.
    - **A Slot's children are its default content.** They render while an instance leaves the slot unfilled, and
      content dropped into the slot replaces them.
    - **A Slot admits the categories it declares,** by default those its parent block admits.
    - **It prints** as `{children}` or `{media}`, with any default content as the fallback (`{media ?? <…/>}`). The TSX
      Lens reads these back as Slot nodes.
      *Extended by ADR-0233 decision 5 (2026-10-16): in printed MDX it is `{props.children}` or `{props.media}`, which
      the MDX Lens reads back as Slot nodes.*
11. **A layout or template with a blocks body marks its hole with an unnamed Slot.** At render, a layout's Slot is filled
    by the page it wraps, and a template's by the page's body. `ArtifactChrome` already passes either in as `children`.
    - **Exactly one hole.** Such a body holds exactly one unnamed Slot. A save that removes it or adds a second is
      refused.
    - **Named Slots** in a layout or template, for regions a page fills separately, are deferred. A page's body is one
      tree.
    - **Layout and template entries compile** as component entries do (decision 14). Layouts made as page entries keep
      working.

    A tenant can therefore build its own layouts and templates from blocks. This amends ADR-0213 §7.
12. **A Repeat's empty state is not a slot.** It stays part of the Repeat field (ADR-0229 decision 9), which any block can
    carry. A component author who wants instances to supply the message can place a named Slot inside the empty state.

### What a component's inside reads

13. **An Authored Component is a closed scope.** Its blocks read its props, through Prop References, and the Records of
    its own Repeats. They never read the Record in scope where an instance sits.
    - **Data comes in through props.** A page binds an instance's prop to a field, and the inside reads the prop. An
      instance inside another component passes the outer component's props on by Prop Reference.
    - **Slot content belongs to the page.** It renders inside the component but reads in the page's scope. It can bind to
      the page's Record, and it never sees the component's props or Records.
    - **A component's Repeat may filter on a prop,** which amends ADR-0229 decision 6. It runs once for each distinct
      value. An instance may bind such a prop to the Record in scope only where exactly one Record is in scope, on a
      Record Page (ADR-0229 decision 7), so the server never runs a query per row.
    - **A Data Condition may test a prop,** which amends ADR-0229 decision 12. A toggle or choice prop can show or hide
      part of a component.
    - **Deferred:** a prop that carries a whole Record, so a component could walk its relations, and a slot whose content
      reads the component's Records.

### Delivery

14. **Components are composed at render, as layouts are.** Each component entry, Authored or source, compiles to its own
    artifact. The renderer sends the addresses of the component artifacts a page uses, including components inside
    components, each at its Publication Pin, as it sends chrome. The client adds them to the components map. A
    component's Repeats are resolved per request with the page's (ADR-0229 decision 4).
    - **Publishing a component updates every page that uses it,** with no republish.
    - **The canvas renders components at their Working Head.** Seeing exactly what guests will get is the Release
      preview's job (editor-parity ticket 14).
    - **Publishing a page is refused** while it uses a component that has never been published, unless that component
      is in the same Release. If a component it uses has a pending draft, publishing offers to add the draft to the
      Release.
    - **A cycle is refused at save.** A component can't contain itself, directly or through another component.
    - **Deleting a component is refused** while any entry's Working Head or Publication Pin uses it. The refusal names
      those entries.
    - **An interface change that strands instances,** such as a prop or slot removed or retyped, is reported at save
      with the affected pages and never refused (ADR-0228 decision 8). A stranded value is ignored at render, and the
      inspector marks it for removal.

### Authoring

15. **Make a component, make a prop, duplicate.**
    - **Make a component** works on one selected block. Selected siblings are first wrapped in a new block, which
      becomes the root. The author names the component, a component entry is created in the site's realm with the
      selection as its body, and an instance replaces the selection. The swap is an ordinary page edit, and undo
      reverts it.
    - **What moves.** The selected block's Block Style moves into the component, because it is the component's look.
      Its Repeat, Data Condition and gates stay on the instance, because they describe where it sits.
    - **Bindings become props.** A Field Binding inside the selection that reads the page's Record becomes a prop, and
      the new instance binds that prop to the same field, so the page renders as before. Literals stay literals.
    - **Make a prop** turns a literal inside a component into a prop whose default is that literal, so no instance
      changes.
    - **Duplicating a component** copies its entry under a new name. Existing instances stay with the original.
16. **A component is edited in place.** "Edit component" on an instance, or a double-click, opens the component where the
    instance sits.
    - **The page dims** and can't be selected. The component renders with this instance's props and slot content.
    - **Editing switches to the component's own Local Session** (beam JS ADR-0004). Undo walks it, Save saves a draft of
      the component, and Publish publishes it as a Release of one (ADR-0226). The page's unsaved changes wait untouched.
    - **A bar shows the reach,** for example "PricingCard — used on 12 pages".
    - **The action is absent** for an author who can't edit the component.
    - **A component also opens alone** from the entries list, rendered with its defaults.
17. **Detach turns an instance into plain blocks.** It applies to Authored Components only, and is refused for code and
    source components. The instance is replaced by a copy of the component's tree at its Working Head, which is what the
    canvas showed, with fresh ids.
    - **Props.** Each Prop Reference becomes the instance's value. A literal stays a literal, and a bound prop becomes
      the same Field Binding, which is valid now that the blocks sit in the page's scope.
    - **Slots.** Each Slot becomes the instance's slot content, or its default content if the slot was unfilled.
    - **Root fields.** The instance's own fields land on the copy's root. Where both set the same style property, the
      instance's value wins.
    - **One level.** Nested instances stay instances. Once variants exist, the copy keeps only the layers for the
      instance's chosen values.
    - **Undo.** Detach is an ordinary page edit. The component is untouched.

### Variants, deferred

18. **A variant will be a Style Layer condition.** A component declares a variant axis as a choice (`size: compact |
    regular`), and each instance picks a value as it sets a choice prop. A Style Layer inside the component may carry the
    choice as a third condition, beside breakpoint and state: `{ breakpoint?, state?, variant?: { axis, value } }`. It
    compiles like a state: the instance's root carries the chosen value as an attribute, and the layer's selector reads
    it. Several axes are several choices. A Code Component's variants are its own props. Nothing is built now. This fixes
    the shape the Block Style and the interface leave room for, under beam JS ADR-0003's deferral.

## Consequences

- **Compiling widens.** `CompileEntryBody::compilable()` admits component, layout and template entries, and the artifact
  route serves them through the same gate.
- **The renderer's payload** gains the component artifacts a page uses, beside `chrome`. Resolving a page's Repeats walks
  into its components' bodies at their Publication Pins.
- **The union gains fields, not arms.** A block's props have seven kinds, with `prop` added. A text node may carry `prop`
  as it carries `field`. A block gains `slots`, and a Slot is a reserved node. The printer and the TSX Lens learn
  JSX-valued attributes as slots, identifiers as Prop References and Slot fallbacks. The generated TypeScript and JSON
  Schema carry them.
- **The manifest grammar gains named slots** in `@schemastud/blockdoc` and `schemastud/laravel-blockdoc` (decision 9).
  `ManifestNodeSource` gains a contributor per realm, its component entries (decision 3). How several contributors
  compose is `rushing/laravel-popcorn` registry-kernel's to rule, as ADR-0228's consequences already say.
- **The canvas** renders an instance with its slot content as editable subtrees, which replaces today's sealed render
  that drops children. No live instance has children, so nothing visible breaks before then. It gains the Props section,
  the in-place component session with its reach bar, Make a component, Make a prop and Detach.
- **Publishing** gains decision 14's checks: components never published, pending drafts, cycles and deletion in use.
- **ADR-0228, ADR-0229 and ADR-0213 carry notes** pointing here, and beam JS ADR-0003's deferral names decision 18.
- **audiostud's editor-promotion ticket 08** is answered by decision 7. That map decides what happens to its ticket.

## Considered and not taken

- **Deep instance overrides keyed by inner node id** (Figma's model). Overrides silently outlive component changes, the
  save can't check them against a declared interface, and a Code Component has no inner nodes to address.
- **No root style on an instance,** positioning it by wrapping it in a block as Webflow authors do. Spacing, width and
  alignment in context are what authors expect to set on the instance.
- **An Authored Component as a symbol local to one page,** or as a pattern copied on insert. The first can't be reused
  across pages, and the second loses "change it once".
- **Retiring source-holding component entries.** They are built, beamux-build's charter brings an app's own `.tsx`
  components in as entries, and a central author still needs them.
- **Props bound inside through the Field Binding** (`field: "props.title"`). It mixes the interface with data: inside a
  Repeat, two different things would be in scope.
- **No props on an Authored Component.** An author would copy a component to change its heading.
- **Named slots as wrapper child nodes** (`<Card.Media>`). They fit the one-child-list grammar, but every Code Component
  would have to pick its slots out of `children`.
- **Slots declared in the interface only,** with the body marking each hole by block id. Printed source carries no ids
  (ADR-0228 decision 3).
- **Inlining components into a page's artifact at publish.** Changing a component would mean republishing every page
  that uses it. ADR-0213 §7 rejected compiling entries together for that chain of invalidation.
- **Pinning a component version per instance,** with an author accepting each update. "Change it once" would become
  "change it once, then accept it on every page".
- **Deriving the tag from the slug,** or referencing the component by entry id. Slugs are unique only per namespace, and
  printed source and the disk mirror carry tags either way.
- **Open scope,** where inner blocks bind to the Record wherever an instance sits. The component would work only where
  those fields are in scope, and every save of a page that places it would re-check its inner bindings.
- **Ejecting a Code Component now,** or a dev-mode `write-package-source`. Drift, reachability and provenance are open,
  and Claude Design also refuses Detach for code components.
- **Variants as named override sets keyed by inner block id** (Webflow's), or as copies of the component. The first adds
  a second store of style beside the Block Style, and the second drifts.
- **Editing a component in place but saving it with the page.** One Save would span two entries, which neither beam JS
  ADR-0004 nor ADR-0225 provides for.
- **Detaching from the Publication Pin.** The author would get something other than what the canvas showed.
- **A separate Outlet node for layouts and templates.** That is two nodes for one idea.
- **A component's rights taken from each page that uses it,** with an ungated artifact. Anyone holding its address could
  read a component used only on gated pages.
