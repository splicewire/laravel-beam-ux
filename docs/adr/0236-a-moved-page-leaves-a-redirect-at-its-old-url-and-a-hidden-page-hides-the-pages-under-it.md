# ADR-0236 — A moved page leaves a Redirect at its old URL, and a hidden page hides the pages under it

Status: **accepted**. Decided in editor-parity ticket 19, 2026-10-16, under the owner's standing instruction of that day
to accept every recommended answer. There was no live exchange; the ticket records each question and its options.
Date: 2026-10-16
Builds on: **ADR-0232** (a version holds a page's URL, a Release can take a page down, a Preview serves a Release),
**ADR-0226** (a Release publishes entries together), **ADR-0209** (the public renderer: §2's catch-all and reserved
prefixes, §5's uniform 404), **ADR-0212** (read rights compose down the containment chain), **ADR-0229** (Record Pages
and a Record's computed `url`), **ADR-0225** (only Publish moves the pin; the activity log is the audit trail).
Amends: ADR-0209 §5. ADR-0232 decisions 3, 6, 11 and 14. ADR-0226 decision 2. ADR-0229 decision 8. Each carries a note
pointing here.
Notes, without amending its rule: `splicewire/tower docs/adr/0008-a-tenants-site-is-a-hosted-site-served-from-its-own-schema-on-its-own-origin.md`
decision 9, whose tenant Site section gains a Redirects seat.
Companions: beam JS `CONTEXT.md` (**Redirect**; **Take-down** amended).

## Context

ADR-0232 put a page's parent and segment in its saved version, so a URL change goes live with its Release, and it let
a Release take a page down. It left what a guest gets at the old URL to editor-parity ticket 19. Measured 2026-10-16:

- **Nothing redirects a moved page.** No beam package or host source declares a redirect store, and no live schema at
  five hosts has a table named for one. The old URL answers §5's uniform 404
  (`src/Http/Controllers/PublicEntryController.php:114-117`).
- **Hosts write redirects as code.** The flagship 301s three folded docs URLs to their containers, behind the docs read
  gate, because "the URLs were NOT orphans" (`~/Herd/splicewire-app/routes/web.php:210-219,234`). It chose no redirect
  for a docs track that left for another host (`:181-184`). The beam site 301s every `/docs/*` URL to the app and keeps
  the rest of the path (`~/Herd/splicewire/routes/web.php:38-43`). A Hosted site holds no code (ADR-0227 decision 1),
  so it has nowhere to write one.
- **A hidden page hides the pages under it in the nav only.** `NavProjector` drops a hidden page with its whole subtree
  (`src/Containment/NavProjector.php:137-138,160`). The renderer tests the publish gate on the requested page alone
  (`src/Http/PublicEntryGate.php:50-54`), and so does the sitemap (`src/Sitemap/EntrySitemapSource.php:57`). A page
  under a hidden page is therefore served and listed in the sitemap, but missing from the nav. Read rights already
  compose down the chain (`src/Access/EntryAccessResolver.php:47-59`). Across five hosts (113 pages; 31 of them
  workflow-managed, all at one host; 2 hidden), no published page sits under a hidden one.
- **A page can be hidden outside any Release.** The entry's Workflow tab fires `unpublish` on one entry
  (`src/Workflow/EntryWorkflowTransitionOp.php:16,28-30`).
- **One signal follows every pipeline write.** Each write through `ParticleWriter` emits `BeamParticlePersisted`
  (`splicewire/laravel-beam` `src/Events/BeamParticlePersisted.php:9-20`), and a saved Eloquent model keeps its previous
  values (`getPrevious()`).
- **The benchmarks keep old links working by default.** Webflow keeps 301 redirects in its site settings, with capture
  groups, and offers a permanent redirect when a static page's slug changes. Shopify offers "Create a URL redirect" when
  a product handle changes. WordPress core redirects an old post slug, and only when the request would otherwise 404.
  Google treats 301 and 308 as a strong signal and 302 and 307 as a weak one, and it treats every 4xx except 429 the same
  way for indexing.

## Decision

### What a Redirect is

1. **A Redirect is a row of its own.** It belongs to one site and lives with that site's entries, in the tenant's
   schema for a Hosted site. It maps an old path to a target: a page, a Record, a path on the same site, or an address
   on another site. A page or Record target is held by identity, so the Redirect reaches it wherever it is now. A
   Redirect isn't versioned. It records its origin instead: the Release or Take-down that left it, a Record's change, or
   an author. It serves no content, and a page's URL is still its containment (ADR-0209, ADR-0229).

### What leaves one

2. **Publish leaves a Redirect at every URL a move takes away.** Publishing through `EntryPublication` does this,
   whether it publishes a Release, a Release of one, a `save-body` call, a seed or an import. A Redirect goes at every URL
   a guest could open before the publish and no page answers after it, and it targets the page that was there. That
   covers the moved pages and every page under them whose URL changed. A page whose segment is root-absolute keeps its
   URL, and a hidden page had no URL a guest could open. A URL that another page takes, as in a swap or a new page at an
   old URL, gets none. A Record Page's old URL keeps its parameter, so `/blog/{slug}` reaches each Record at its new URL.
   The Release page and the Preview bar list these Redirects. The Release has no per-URL switch; an unwanted Redirect is
   removed from the list.
3. **A Record's URL change leaves one.** When the field a Record Page binds by changes on a Record, a Redirect is left
   from the old URL to the Record. It resolves through the Collection's one Record Page (ADR-0229 decision 11). It is
   caught on the write signal and built with Record Pages. A write that bypasses `ParticleWriter` leaves none.
4. **A Take-down may name a page.** Every URL the Take-down hides (decision 8) then redirects there. The target must be
   live once the Release publishes and must not be hidden by it. Publish refuses otherwise, and the Preview bar lists
   that refusal with ADR-0232 decision 11's. With no target, those URLs answer the uniform 404. Taking a page down from
   its Workflow tab becomes a Take-down in a Release of one, as publishing one entry is a Release of one (ADR-0226
   decision 1).
5. **Authors add Redirects by hand.** A hand-made Redirect starts from an exact path, or from a path and everything
   under it, with the rest of the path carried to the target. It goes to a page, a path or an address. It is permanent by
   default or temporary by choice. It takes effect on save, as a site's domains do, but it never answers where a page
   does (decision 7). One aimed at a page that hasn't been published answers the uniform 404 until the page is, so a
   relaunch's Redirects go live with its Release. Saving one is refused when a page answers its path, when the renderer
   never receives its path (a reserved prefix, §2), or when it would reach itself. Capture groups are deferred.
6. **Deleting a page leaves no Redirect by itself.** The URLs a delete takes off the site, the page's own and those that
   compose through it, answer the uniform 404 unless an author adds a Redirect. Redirects that named the page stop
   answering, and the doctor names them. Restoring the page brings them back.

### What a guest gets

7. **A page always wins.** When a page or a Record answers a URL, it is served, and a Redirect at that URL never fires.
   Publish removes a Redirect at a URL it puts a page on, and the Preview bar says so beforehand.
8. **A hidden page hides the pages under it.** The renderer, the artifact route and the sitemap test the publish gate on
   every page of the chain, as the nav already does and as read rights already compose. Each page keeps its own marking,
   so publishing the parent again brings the subtree back as it was. A page published under a hidden page stays hidden,
   and the bar says so. A Take-down therefore takes down its page and every page under it.
9. **A Redirect answers in one response.** It answers 301, or 302 for a temporary one, and carries the guest's query
   string along. A page target resolves to that page's current URL. When a Redirect lands on a URL no visible page
   answers, such as a hidden target or a path that is itself a Redirect, the server looks that URL up in turn, for a
   bounded number of steps. A loop or a dead end answers the uniform 404, and the doctor names it.
10. **A Redirect answers only a reader who may read its target.** A page target must be visible and pass the reader's
    read rights (ADR-0212). A Record target needs the reader's own read (ADR-0229 decision 5). Anyone else gets the
    uniform 404, so a denied page and an absent one still look the same. An answer that depends on the reader is sent
    `no-store, private`.
11. **A URL with no page and no Redirect answers the uniform 404,** a taken-down page's included. 410 is deferred.
12. **In a Preview, Redirects answer as the Release would leave them.** That includes its moves and its Take-downs'
    targets. They always answer 302 with `no-store`, so a reviewer's browser keeps nothing that outlives the Preview. The
    bar names each URL the Release moves or takes down and where it will go, which answers ADR-0232 decision 14.
13. **The sitemap never lists a Redirect.** It lists the URLs pages answer at, read from the row, so a moved URL leaves
    it when its Release publishes.

### Where they're managed

14. **A Redirects seat in the Site section,** beside Releases, on the central site (the operator realm) and on a Hosted
    site (`splicewire/tower` ADR-0008 decision 9). It lists each Redirect's path, target, kind and origin, and it adds,
    edits and removes them. A page's settings show the Redirects that lead to it. Managing them takes the rights that
    publishing takes on that site (ADR-0008 decision 7). A Scaffold Pack copy carries no Redirects. A lapsed Entitlement
    takes them dark with the site and keeps them.

## Consequences

- **A Redirect resource.** `laravel-beam-ux` gains a Redirect `#[ParticleResource]` with one declared Data class, made
  with `splicewire:beam:make:particle-resource`. Its table ships in the shared migrations, so central and tenant schemas
  both get one.
- **The renderer looks a Redirect up on every path it would otherwise answer with the uniform 404.** A denied page
  costs the same lookup as an absent one, so the timing stays uniform (§5). A page that resolves pays nothing.
- **Publish composes each moved page's subtree URLs before and after,** as its URL-collision check already does with
  all of a Release's moves applied (ADR-0232 decision 2).
- **Inertia visits.** A client-side visit to an old URL follows a Redirect within the site. A Redirect to another site
  answers Inertia's external-location response (`Inertia::location`), because a client-side visit can't follow a
  redirect to another origin.
- **Caching.** A live Redirect is cached briefly if at all, so a URL a page later reclaims stops redirecting soon after.
- **The doctor** reports a Redirect that dangles (its target deleted or never published), loops, sits at a URL a page
  answers, or is shadowed by a host route registered before the renderer (`BeamUxRouteShadowAudit`). It also reports a
  hidden page with published pages under it, so a structural page left unpublished is found before readers notice.
- **Activity.** Every Redirect write records an activity row with its causer (ADR-0225 decision 8). A Release's publish
  lists the Redirects it made.
- **Host-coded redirects keep working** and may move into the list. A route a host registers before the renderer still
  wins (ADR-0209 §2).
- **Links inside other pages keep their literal URLs** and reach a moved page through its Redirect. Whether a link
  should name a page by identity isn't decided here.

## Considered and not taken

- **Old URLs as a field on the entry, held by its version,** like Hugo's `aliases` or WordPress's stored old slugs. A
  rollback to an older version would drop the newer old URLs, and a Take-down would hide them with the page. A field
  also can't hold a redirect to another site, from a URL no entry had, or for a Record.
- **Redirects as Release members.** A Release's moves already imply its Redirects, and a hand-made one aimed at an
  unpublished page waits for it. A second kind of member adds review surface and no capability.
- **A per-URL switch on the Release.** It stores a choice on every Release for a case the list already covers.
- **One "everything under" Redirect per moved section.** A page later moved out of the section would chain, and a
  single URL couldn't be retired when a page reclaims it.
- **Collapsing chains in the data,** by retargeting Redirects when their target moves or is taken down. It loses the
  way back: when a taken-down page returns, the Redirects that named it should reach it again.
- **410 Gone for a page taken down.** Google drops a 404 the same way. The renderer would have to remember which URLs
  once held pages, and a 410 tells a guest a page was there, which §5 hides for a gated page.
- **A Take-down that fires `unpublish` on every page under it.** Each page would lose its own state, and bringing a
  section back would take a Release of every page in it.
- **Pages under a hidden page stay served,** as the renderer does today. The nav already can't show them, so only a
  link someone kept would reach them, and the sitemap would keep listing them.
- **Refusing to delete a page guests can reach.** At four of the five hosts measured, no page is workflow-managed, so
  no page there could be taken down first.
- **Hand-made Redirects through Releases.** ADR-0225's rule that only Publish changes what a guest sees protects pages,
  and a Redirect never answers where a page does.
- **Capture groups** (`/old/(.*)` to `/new/%1`). The estate's cases are exact paths and one prefix that keeps the rest
  of the path.
