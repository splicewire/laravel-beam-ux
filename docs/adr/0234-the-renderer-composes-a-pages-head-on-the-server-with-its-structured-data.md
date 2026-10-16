# ADR-0234 — The renderer composes a page's head on the server, with its Structured Data

Status: **accepted**. Decided in editor-parity ticket 17, 2026-10-16, under the owner's standing instruction of that day
to accept every recommended answer. There was no live exchange; the ticket records each question and its options.
Date: 2026-10-16
Builds on: **ADR-0209** (the public renderer: §6's Inertia response, §8's restricted chain), **ADR-0213** (what a package
may ship), **ADR-0228** (the body grammar and the Manifest Profile), **ADR-0229** (Collections, Record Pages, a Record's
computed `url`), **ADR-0232** (a version holds what a guest sees; a Preview is `noindex` and fires no analytics),
**ADR-0233** (frontmatter is version fields; each host converts once).
Amends: ADR-0209's consequence on server-side rendering. ADR-0232 decision 1 (the SEO fields now exist). ADR-0228
decision 2 (a block may carry a Click Event). Each carries a note pointing here.
Companions: `splicewire/laravel-beam-analytics docs/adr/0237-every-site-carries-the-analytics-tag-off-until-it-has-an-id-and-a-hosted-site-measures-with-its-own-ga4-id.md`
(the Analytics Tag, Analytics Events, a Hosted site's Site Settings) and beam JS `CONTEXT.md` (**Page Head**, **SEO
Fields**, **Page Type**, **Structured Data**, **Click Event**).
Cites, without editing: flagship `docs/adr/0050-schema-org-is-a-boundary-projection-never-in-engine-src.md` (concrete
schema.org types stay out of the leaf and out of engine source) and `splicewire/tower docs/adr/0008-a-tenants-site-is-a-hosted-site-served-from-its-own-schema-on-its-own-origin.md`
(the Site Host).

## Context

Measured 2026-10-16:

- **The packaged page sets a title, in the browser, and nothing else.** `SiteEntry` renders `<Head title>` only
  (beam JS `beam-ux/src/pages/SiteEntry.tsx:160`). The renderer's props are `entry`, `artifact`, `chrome` and `nav`
  (`src/Http/Controllers/PublicEntryController.php:127-158`). No description, social tag or JSON-LD comes from the
  shared path.
- **Nothing a crawler without JavaScript reads.** Every Inertia host fetched on 2026-10-14 rendered on the client, and no
  SSR server ran (editor-parity research 16; on 2026-10-16 still nothing listens on the SSR port the six SSR-enabled
  hosts share). The five starters share one root template, which prints the brand's name as the title where SSR doesn't
  run, with no description, social tag or JSON-LD (`laravel-beam-starter` `resources/views/app.blade.php:51-53`), so
  every page of a fresh site reads the same title to a link-preview bot.
- **Inertia can carry a server-made head.** From 3.5, `@inertiajs/react`'s `serverHead` option holds head elements a
  page prop supplies (`head` by default), marks them as its own, and re-syncs them on every navigation
  (`@inertiajs/core` `dist/index.js:3886-3895`; `@inertiajs/react` `dist/index.js:97,133`). The Laravel side prints a
  head only from an SSR response; otherwise `<x-inertia::head>` prints its slot (`inertia-laravel`
  `src/View/Components/Head.php:21-24`). No host or beam package uses it. Hosts run 3.4.0 (numero, which lacks it) to
  3.6.1, and every starter's options come from `beamInertiaOptions` (beam JS `beam-inertia/src/index.tsx:47-140`).
- **SEO fields were reserved, not named.** ADR-0232 decision 1 puts "the per-page SEO fields once they exist" in the
  version, and ADR-0233 decision 3 makes frontmatter keys page settings, SEO fields and Page Fields.
  `EntryFrontmatterData` declares none (`src/Data/EntryFrontmatterData.php:51-79`). Across 119 MDX files under ten hosts'
  `resources/`, `excerpt` appears in 79 (the meta description, the JSON-LD description, and a listing teaser at
  stephenrushing), `schemaType` in 19 (`Article` 10, `WebPage` 8, `Person` 1), `datePublished` in 8 and `schemaName` in
  1; no file carries `description`, `image` or `og`.
- **Three JSON-LD builders, five copies, two planes.** numero projects Data classes through
  `schemastud/laravel-data-schema-org`; `@splicewire/beam-mdx` builds a node from frontmatter in the browser; thingsontv
  uses its own bag. Five hosts carry their own copy of `JsonLd`, in three contents. The runbook keeps server projectors
  for DTO pages and frontmatter for MDX pages (`splicewire-beam-runbook` `references/structured-data.md:12-28`). An MDX
  page is now an entry whose frontmatter is version fields (ADR-0233), so the second plane has no subject left.
- **The projector already exists.** `SchemaOrgRegistry` projects a Data object by its class
  (`schemastud/laravel-data-schema-org` `src/SchemaOrgRegistry.php:99-102`), `SchemaOrgGraph` assembles one `@graph`
  deduplicated by `@id` (`src/SchemaOrgGraph.php:29-55`), and a caller may pass the base URI the `@id`s hang off
  (`src/ProjectionContext.php:37-40`; the default is `APP_URL`, `config/data-schema-org.php:9`). Among the splicewire
  packages, one class maps a concrete type: `laravel-beam-commerce`'s `OfferData` to Product (`src/Data/OfferData.php:28`).
- **Two ports already answer per request.** The sitemap's base URL is a port the tenancy layer rebinds
  (`splicewire/laravel-beam-sitemap` `src/Resolvers/SitemapBaseUrlResolver.php:5-15`;
  `splicewire/laravel-beam-tenancy` `src/BeamTenancyServiceProvider.php:486-493`). The brand is a port with one binding,
  the install's config (`splicewire/laravel-beam` `src/Brand/ConfigBrandResolver.php:8-13`, bound at
  `src/BeamServiceProvider.php:375`), and nothing rebinds it.
- **No entry records when it was first published.** `published_version` is a version id
  (`database/migrations/shared/create_beam_ux_entries_table.php.stub:155`), and no column holds a publish date.

## Decision

### The Page Head

1. **The renderer composes each page's head on the server.** The **Page Head** holds the title, the description, the
   canonical URL, robots, the social tags (Open Graph and a Twitter card) and the page's Structured Data. It travels as
   a declared Data prop, rendered once, on the server. The root template prints it on the first load wherever SSR
   doesn't run (as `<x-inertia::head>`'s slot), and Inertia's server head keeps it current as a visitor navigates. The
   packaged Inertia options turn that on, so a host on them edits nothing in its `app.tsx`; a host with its own options
   turns it on when it adopts the editor kit (beam JS ADR-0005). The brand's title template applies once. SSR
   stays a host's choice for body content (ADR-0209's consequence) and needs nothing extra for the head.
2. **A host-coded page uses the same head.** A controller composes one with a packaged helper and passes it as the same
   prop. A page that passes none keeps the root template's fallback title.
3. **A page a guest can't read gets its title and `noindex`, nothing more.** That is ADR-0209 §8's restricted chain: its
   description, social tags and Structured Data are never composed. A Preview is `noindex` already (ADR-0232 decision 6).
4. **The canonical URL and every `@id` hang off the sitemap's base-URL port,** so they agree with the sitemap, and a Site
   Host's pages name the Site Host.
5. **On a Site Host the brand is the Hosted site's own.** `Brand::for()` answers the name (the tenant's by default) and
   the logo held in the Hosted site's Site Settings (ADR-0237 decision 6), through the `BrandResolver` port rebound by
   the tenancy layer, as the sitemap's base URL is. The title template, the social tags and the Structured Data read it.

### SEO Fields

6. **A version's SEO Fields are its description, social image, Page Type and published date.** The description is one
   or two sentences for search results, link previews and listings. The social image comes from the media library. The
   published date, when empty, is the date of the page's first Publish, which Publish now records along with the date of
   its latest. The field exists for content first published elsewhere. Title stays a page setting. The SEO Fields are
   edited in page settings, saved with the body and live on Publish (ADR-0232 decisions 1 and 2). A file writes them as
   frontmatter, and conversion (ADR-0233 decision 9) reads today's `excerpt` as the description, `schemaType` as the
   Page Type and `datePublished` as the published date. `schemaName` isn't a field; it is kept and reported (ADR-0233
   decision 3).
7. **A child page's SEO Fields are Record fields of its parent's child-page Collection,** beside its title, its computed
   `url` and its Page Fields (ADR-0229 decisions 1 and 11; ADR-0233 decision 4), so a listing card binds them.
8. **Not now:** an SEO title apart from the title, Open Graph overrides, a per-page noindex switch, a canonical override
   and a site-wide default social image. Nothing in the estate asks for them.

### Structured Data

9. **The renderer projects one `@graph` per page, on the server, with `schemastud/laravel-data-schema-org`.**
   - **The page's node,** typed by its Page Type (WebPage when unset), from its title, SEO Fields, canonical URL and
     publish dates.
   - **A BreadcrumbList** from the chain the renderer already walks, on every page below the root.
   - **On the home page, the WebSite and its publisher:** an Organization built from the brand, or a Person where the
     host declares its publisher to be one.
   - **On a Record Page, the Record's node,** projected by the projector registered for its Data class (as numero
     registers them today), as the page's main entity. A Record whose class has no projector adds nothing.

   `laravel-beam-ux` requires `schemastud/laravel-data-schema-org` and maps its own page fields, as
   `laravel-beam-commerce` maps `OfferData`. That keeps flagship ADR-0050 decision 4: concrete types stay out of the leaf
   and out of engine source, and live in the package that owns the page.
10. **Page Types are WebPage (the default), Article, CollectionPage and ProfilePage,** the four the estate uses. An
    Article's author and publisher are the site's publisher. A ProfilePage's main entity is the Record on a Record Page,
    and otherwise the site's publisher. A further type is a line in the package, not a decision.
11. **The two planes end.** Structured Data has one producer, the renderer on the server. `@splicewire/beam-mdx`'s
    `contentJsonLd` and `ContentShow`'s head (its description meta and `JsonLd`) retire with each host's conversion, with
    `ContentShow`'s chrome (ADR-0233 decision 5), and so do the five host copies of `JsonLd` and `content/show.tsx`. A
    host-coded page adds its nodes through the same head (decision 2). The runbook's two-plane rule and its rule to keep
    JSON-LD as copy-paste give way to this.
12. **Deferred, with its shape set: Structured Data from blocks.** A Code Component's `#[NodeType]` Data class may
    register a projector, and the renderer adds its node to the page's graph: an FAQ block's questions, a video's
    VideoObject, beam-mdx's `citation` nodes for live references (1 file). A registered Collection's Records
    (ADR-0235) add no node until a schema can declare a schema.org type.

### Click Events

13. **A block may name a Click Event.** It is a field on any block, as a Repeat or a Data Condition is: a name from the
    Event Vocabulary (ADR-0237 decision 8) and an optional label, chosen in the inspector. A save checks the name against
    the vocabulary the profile serves (ADR-0228 decision 8). The printer writes it as `bdEvent`, and both lenses read it
    back. It is legal on a Hosted site, since it declares and runs no code. The site's runtime reports it when a visitor
    clicks the block. Nothing fires in the canvas or in a Preview.
14. **The Manifest Profile serves the Event Vocabulary,** as it serves Collections (ADR-0229 decision 1): the package's
    names and the host's on a central profile, and the install's on a Hosted-site profile.

## Consequences

- **The renderer gains a declared head prop** beside `entry`, `artifact`, `chrome` and `nav`, and `EntryPageProps::CORE`
  grows to include it. A package contributing props for its layout can't replace it.
- **Every root template places the packaged head slot once:** the starters' shared template, then each host's at
  adoption. The flagship's template prints `@inertiaHead`, so its slot moves inside the component's fallback. The doctor
  names a root template without it.
- **`beamInertiaOptions` sets Inertia's server head,** and `SiteEntry`'s `<Head title>` gives way to it. A host must run
  `@inertiajs/react` 3.5 or later: numero moves off 3.4.0 when it adopts (ticket 10's "wait" row).
- **Publish records two dates on the row,** the first and the latest, with the other fields Publish writes
  (ADR-0232 decision 1).
- **The flagship's static meta description** (`~/Herd/splicewire-app/resources/views/app.blade.php:18`) gives way to each
  page's own.
- **The JsonNode union gains a field.** The Click Event joins `style`, `repeat` and `when` in the generated TypeScript
  and JSON Schema, the printer and the TSX and MDX lenses.
- **`laravel-data-schema-org` reaches every site** with the renderer, where it was installed at seven hosts and used at
  two.

## Considered and not taken

- **An SSR process on every host.** It is a supervised Node process per host in production, the six hosts that enable
  it today all point at one local address (`http://127.0.0.1:13714`), and it needs every component to render without
  `window` (`ContentShow` builds its canonical from `window.location.origin`, which is empty under SSR). The head alone
  doesn't need it.
- **Head work kept in the browser.** A crawler that runs no JavaScript, and every link-preview bot, would keep reading
  the brand's name as every page's title, with no description, image or Structured Data.
- **A packaged root view.** The root template carries the host's palette and fonts (`laravel-beam-starter`
  `resources/views/app.blade.php:23-31,37`), which ADR-0213 (i) keeps out of packages, and the host's Vite entries.
- **Middleware that injects tags into the HTML.** It rewrites a response it didn't render, and the analytics PRD
  rejected it for placement.
- **The head rendered twice,** in PHP for the first load and in React on navigation. Two renderers drift; Inertia's own
  server head takes the server's elements as they are.
- **Two planes kept.** MDX pages are entries now (ADR-0233), and a client-side builder needs SSR to reach raw HTML.
- **A raw JSON-LD field per page.** It is code in all but name, a Hosted site refuses code (ADR-0227 decision 1), and it
  would drift from the page it describes.
- **Webflow's fuller SEO set** (an SEO title, Open Graph overrides, a noindex switch, a canonical override). No page in
  the estate sets any of them.
- **Every schema.org page type, or one the author types in.** An unlisted type yields nodes no search engine reads and
  no reviewer checks; FAQPage needs Question nodes from blocks (decision 12).
- **Click tracking left to GTM triggers.** Block Styles compile to hashed classes (beam JS ADR-0003), so a CSS selector
  breaks on the next edit, the editor can't show the trigger, and a Hosted site has no container to configure
  (ADR-0237 decision 5).
- **Free-text event names in the inspector.** The same event would be spelled three ways across a site.
