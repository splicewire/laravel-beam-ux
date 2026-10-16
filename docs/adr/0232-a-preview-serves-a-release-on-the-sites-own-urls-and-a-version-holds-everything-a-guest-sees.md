# ADR-0232 — A Preview serves a Release on the site's own URLs, and a version holds everything a guest sees

Status: **accepted**. Decided in editor-parity ticket 14, 2026-10-15.
Date: 2026-10-15
Builds on: **ADR-0225** (Working Head and Publication Pin; a save is a draft), **ADR-0226** (a Release publishes entries
together), **ADR-0209** (the public renderer: §5's uniform 404, §7's compiled artifacts), **ADR-0229** (Records are
resolved per request as the visitor), **ADR-0231** (components compose at render), **ADR-0212** (the read rights),
**ADR-0227** (the renderer reads the tree of the domain's owner).
Amends: ADR-0225 decisions 1 and 3. ADR-0226 decisions 1 and 2. ADR-0209 §7. ADR-0229 decision 4. Each carries a note
pointing here.
Cites, without editing: `splicewire/tower docs/adr/0008-a-tenants-site-is-a-hosted-site-served-from-its-own-schema-on-its-own-origin.md`
(a Hosted site's origin carries no app session, and a lapsed Entitlement takes the site dark) and
`splicewire/laravel-beam docs/adr/0230-a-guest-reads-a-resource-only-through-its-declared-public-read.md` (what a guest
reads).
Companions: beam JS `CONTEXT.md` (**Preview**, **Preview Link**, **Take-down** and **Reach**; **Release** and
**Publication Pin** amended).

## Context

ADR-0226 made the Release the unit of publishing and left previewing one to editor-parity ticket 14. Nothing renders
unpublished content today, and some of what a guest sees isn't held back by a version at all:

- **No Release exists in code.** `rg -w` for `Release`, `EntryRelease` and `ReleaseData` over `src` finds nothing.
- **Only the published artifact exists.** `EntryArtifactStore::version()` keys the address on the compiler generation
  and `published_version`, falling back to the head (`src/Compile/EntryArtifactStore.php:81-111`), and `put()` deletes
  every other artifact the entry has (`:140-152`). The artifact route serves only the current one. Its `{version}`
  segment picks the cache header and nothing else (`src/Http/Controllers/EntryArtifactController.php:53-77`).
- **Compile and mirror read the working copy** (`src/Compile/CompileEntryBody.php:117-140`,
  `src/Publish/EntryPublication.php:525-546`). That is right only because Publish pins the working copy (`:492-516`).
- **A version freezes the particle only:** its schema, payload and meta (`splicewire/laravel-beam`
  `src/Models/BeamParticle.php:138-147`). Title, parent, segment, nav order, nav group, layout and template are row
  columns that the entry form writes directly (`src/Data/BeamUxEntryInputData.php:54-73`), so they go live when it saves.
- **The theme goes live on save.** The theme editor calls `save-body` ("Saved tokens reach every reader on their next
  page load", beam JS `beam-ux/src/ThemeEditor.tsx:60,106`), and `ThemeResolver::bodyFor()` reads the working payload
  (`src/Theme/ThemeResolver.php:235-245`).
- **Visibility is the workflow marking** (`EntryPublishGate`). A Publish can make a hidden entry visible (ADR-0225
  decision 4), but nothing can hide one as part of a Release.
- **Signed links can't be revoked.** `ParticleOperation` rides Laravel's `temporarySignedRoute()` and
  `hasValidSignature()` unchanged (`splicewire/laravel-beam` `src/Particle/ParticleOperation.php:324-325`).
- **A Hosted site's origin carries no app session** (`splicewire/tower` ADR-0008 decision 4), so nobody there can be
  recognised by a login.
- **Nothing redirects a moved page.** No entry has a redirect store or route.

## Decision

### What a Release publishes

1. **A version holds what a guest sees.** A saved version holds the body and the entry fields a guest sees: the title,
   the parent and segment (the URL), the nav order and nav group, the layout and template, and the per-page SEO fields
   once they exist (editor-parity ticket 09). They are saved with the draft and go live on Publish, and the row's copies
   become something Publish writes, as it writes the mirror. Restore and a rollback Release bring old values back. These
   stay on the row and stay live: who may read (traverse, access and requirements; ADR-0212), where the entry lives
   (realm, namespace, placement, driver and residency), its slug, its schema and its workflow marking.
   *Amended by ADR-0233 decisions 3 and 5 (2026-10-16): a version also holds the entry's File Language and its Page
   Fields, and an MDX page's frontmatter is these fields printed.*
   *Amended by ADR-0234 decision 6 (2026-10-16): the SEO fields exist. They are the description, the social image, the
   Page Type and the published date.*
2. **Page settings are edited in the editor and saved with the body.** One Save makes one version holding both, and the
   Local Session's undo covers both (beam JS ADR-0004). Publish writes the fields to the row. The entry form keeps
   creating entries and editing the fields that stay live. The row holds published values only, so a page that has
   never been published has no URL on the row, and a draft never occupies a URL. A Release's URL changes are checked
   when it publishes, with all its moves applied together, so two pages can swap URLs. A Release is refused if two pages
   would share a URL, or if a page would sit under a parent that has never been published and isn't in the Release. The
   structure panel shows authors the draft tree.
3. **A Release can take a page down.** A **Take-down** is a member with no version. When the Release publishes, the
   entry's `unpublish` transition fires under its own guard. Only an entry bound to a workflow can be taken down, since
   an entry without one has no hidden state. A hidden member becomes visible when the Release publishes, as a single
   Publish already makes it (ADR-0225 decision 4). Taking a page down needs the publish ability on it, as any member does
   (ADR-0226 decision 7).
   *Amended by ADR-0236 decisions 4 and 8 (2026-10-16): a Take-down hides the pages under its page too, because a hidden
   page hides its subtree, and it may name a page that every URL it hides redirects to. Taking a page down from its
   Workflow tab is a Take-down in a Release of one.*
4. **The theme follows its Publication Pin.** The theme editor saves drafts, and `ThemeResolver` reads the pinned
   version. A theme change can join a Release and be previewed. A Preview shows the theme's `site` slice. Its `shell` and
   `canvas` slices change the app when the theme publishes. The resolver must read the pin before the editor stops
   publishing on save, because a resolver that reads the working copy would show every theme draft to readers.

### What a Preview is

5. **A Preview shows a Release, or one entry at one saved version.** In a Release's Preview, each member shows at its
   fixed version and a Take-down's page is gone. A version preview shows one entry at its Working Head (from the
   editor's Preview) or at a past version (from History, before a Restore). Every other entry shows at its Publication
   Pin. A Preview shows saved versions only: with unsaved Local Session changes, the editor offers Save first. A version
   preview stores nothing. There is no "all drafts" view. A Preview layers one Release over the live site. Previewing the
   site as of a date, with every Release scheduled before it, is deferred.
6. **A Preview is a mode on the site's own URLs.** The app mints a signed link. The site's origin verifies it and sets
   a short-lived, encrypted preview cookie for that origin. While the cookie is present:
   - the page, the fields of every entry on its chain (its URL, layout and template), its chrome, its components and the
     nav are served at the previewed versions;
   - the publish gate answers as the Release would leave it: a member is visible, and a Take-down gets ADR-0209 §5's
     uniform 404;
   - artifacts come through a preview artifact route that checks the same context. The public artifact route never
     serves a draft's;
   - the bindings declared in the previewed versions run in place of the published body's (ADR-0229 decision 4), still
     read as the preview's viewer;
   - every response is `no-store, private` and `noindex`, and a bar names what is previewed and offers Exit.

   Links inside the site keep working, because they are its real URLs. The same link works on the central site, where a
   reviewer has a session, and on a Site Host, where nobody does.

   *Amended by ADR-0236 decisions 8 and 12 (2026-10-16): a Take-down's page and the pages under it get its Redirect, or
   the uniform 404 when it names none, and the Release's other Redirects answer as it would leave them, always 302 and
   `no-store`.*
7. **Artifacts are compiled at Save, into each version's own address.** The address hashes the compiler generation and
   the version id. An entry keeps the artifacts of its Publication Pin, its Working Head and its versions in open
   Releases, and the rest are pruned. A version that joins a Release without an artifact, such as an earlier version in
   a rollback Release, is compiled when it joins. A past version opened from History is compiled first if its artifact
   was pruned. No read path compiles. Save is never refused: it reports `compileError` (ADR-0209, amendment A of
   beam-docs-satellite ticket 06). Publish moves pins onto artifacts that already exist, compiling only what's missing,
   and refuses a member that doesn't compile. The mirror still writes on Publish only, and the public artifact route
   still serves only the pinned artifact.
8. **Who may open a Preview.** Signed in, anyone who can read the Release can open it: its members' authors and its
   workflow reviewers. A version preview is for anyone who can edit that entry. A **Preview Link** opens a Release's
   Preview without an account. Links are turned on per Release, and anyone who can edit the Release can create one. A
   link expires, after 7 days by default, and can be revoked: the Release holds the links' secret, and rotating it ends
   every link. All of a Release's links end when it publishes or is discarded. A version preview has no Preview Link. To
   share a draft, put it in a Release.
9. **A Preview reads as a guest by default.** Bound blocks read through each resource's Public Read
   (`splicewire/laravel-beam` ADR-0230), and the nav and the gates answer as they would for a guest. A reviewer signed in
   on the site's origin, which only the central site allows, can switch to **View as me**. A Preview Link's holder and
   every Preview on a Site Host read as guests.

### What a Preview shows

10. **The changed pages and their Reach.** The Release page and the preview bar list the changed pages: the Release's own
    pages first, then, under each component, layout or template member, the pages it reaches, with a count, and "every
    page" under a theme. Any of them opens in the Preview. A Record Page opens on a Record the viewer picks, from those
    the preview's viewer may read. A component that no page uses has no page to open.
11. **The Preview lists everything that would stop Publish.** It renders what the Release holds, and the bar lists every
    reason Publish would refuse the Release, each linked to the action that fixes it:
    - a member whose Publication Pin moved after it joined. It renders at its fixed version, with "rebase onto theirs" or
      "drop the entry" (ADR-0226 decision 6);
    - two pages that would share a URL, or a page under a parent that won't be published (decision 2);
    - a member that doesn't compile. The compiler's message shows where its body would be (decision 7);
    - a page that uses a component that has never been published and isn't in the Release. A placeholder renders in the
      component's place (ADR-0231 decision 14).

    The bar also lists two notes that don't stop Publish: a component a member uses that has a pending draft, which
    could join the Release (ADR-0231 decision 14), and a member that is also in another open Release, with that
    Release's schedule.

    *Amended by ADR-0236 decisions 4, 7 and 8 (2026-10-16): the bar also refuses a Take-down whose redirect target won't
    be live once the Release publishes, or would be hidden by it. It notes a Redirect that a page in the Release will
    replace, and a page published under a hidden page, which stays hidden.*
12. **A previewed page writes nothing.** Forms render and validate but don't submit, and a note says so. No analytics tag
    or event fires. Every page is `noindex`, and the sitemap and robots files aren't served. Links to other sites work
    normally.
13. **Before and after.** The bar switches the same URL between the Preview and the live site. Highlighting changed blocks
    is deferred. Stable block ids (ADR-0228) make it possible later.
14. **Moved and taken-down URLs.** The bar names every URL the Release moves or takes down, and what a guest will get at
    each. Whether an old URL redirects is editor-parity ticket 19.
    *Answered by ADR-0236 decisions 2, 4 and 12 (2026-10-16): each moved URL redirects to its page's new URL, a
    taken-down URL redirects to the Take-down's target or answers the uniform 404, and the bar names each one.*

### Where and for how long

15. **Where a Preview opens.**
    - **The Release page:** a Preview button and the changed-pages list. The workflow review step happens on this page,
      so a review request links here.
    - **The editor:** Preview opens the entry at its Working Head. The Release in the Publish dialog, whether new,
      existing or scheduled, has its own Preview.
    - **The History panel:** Preview on each version.
    - **A tenant's Site section:** its Releases, opening on the Site Host.
16. **When a Preview ends.** The signed link only switches the mode on. A signed-in viewer's link lasts a few minutes,
    long enough to set the cookie, and the cookie lasts until the browser closes, 8 hours at most. Every request checks
    again that the Release is still open, that the link's secret still matches and that the version still exists. A
    Preview works in every open state of the Release: draft, in review, approved and scheduled. After the Release
    publishes, a preview request opens the live page with a note ("This Release was published on <date>; you're seeing
    the live site"), and a discarded Release gets a similar note. Exit clears the cookie.
17. **A Hosted site previews on its Site Host.** Its links are verified there, every viewer there reads as a guest,
    Preview Links work the same way, and a lapsed Entitlement takes its previews dark with the site (`splicewire/tower`
    ADR-0008 decision 8).

## Consequences

- **Publishing a Release reads each member's fixed version, not its working copy.** A member's version can be behind its
  Working Head (ADR-0226 decision 6), so compiling and mirroring must use that version's snapshot.
  `CompileEntryBody::sourceFor()` and `EntryPublication::mirrorAndCompile()` read the working copy today.
- **`EntryArtifactStore` keys by version id and stops pruning in `put()`.** A separate prune keeps decision 7's set. The
  `beam:ux:compile` backfill and the doctor's missing-artifact check cover the same set, and a compiler generation bump
  recompiles it through the backfill, as today.
- **The row's readers don't change.** The renderer's walk, `PublicEntryGate`, `ChromeEntryResolver`, `NavProjector`, the
  URL resolver and the sitemap keep reading row columns, which now hold published values. Only the editor and a Preview
  read a version's fields.
- **Authoring views show the Working Head's fields.** A page that has never been published has no title or place on the
  row, so the entries list and the structure panel read them from its Working Head.
- **Every writer of the moved fields writes a version.** The editor's page settings save with the body, and the entry
  form's update stops writing them. A writer that publishes directly, such as a seed, a disk import or a pristine
  re-assertion (ADR-0215), publishes through `EntryPublication`.
- **The `beam-ux-entry` resource changes shape.** Title, parent, segment, nav order, nav group, layout and template leave
  its update input and stay in its create input, which seeds the first version.
- **The Release stores a secret** for its Preview Links, because Laravel's signed URLs can't be revoked on their own.
- **A cache in front of a site must pass a request that carries the preview cookie through** to the renderer. Deployment
  is deferred, so this is a note for whoever configures one.
- **The theme editor's note changes** from "Saved tokens reach every reader on their next page load" when it moves to
  drafts. Until `ThemeResolver` reads the pin, the editor stays on `save-body`.
- **The app shell's view-as-visitor toggle** (beam JS `beam-ux/src/appshell/shellMode.ts`) previews what an unentitled
  visitor gets in the app, not the site, and stays as it is.

## Considered and not taken

- **The fields stay live on the row.** A title or URL change would go live on save, outside the review and scheduling a
  Release exists for, and a Preview would have nothing pending to show for it.
- **One form with two kinds of save** for page settings. It hides two save behaviours inside one form.
- **A Release only moves pins,** with visibility a separate act for each entry. A relaunch, with new pages up and old
  pages down, couldn't be reviewed or scheduled as one change.
- **The theme stays live on save, as an exception.** A palette change is the change most worth seeing across the site
  before it ships.
- **Separate preview URLs** (`/_preview/{release}/{path}`). Links in a page are real site paths, so one click leaves the
  Preview unless every link is rewritten.
- **Rendering inside the workspace.** The domain, theme and links differ from what a visitor gets, and app hosts don't
  serve the site (`splicewire/tower` ADR-0008 decision 4).
- **A staging environment as the Preview.** Deployment is separately selected scope.
- **Compiling when a version joins a Release or is previewed,** as the rule rather than the fallback. A broken body would
  surface only when someone looked at it. Compiling on a read puts Node on the request path, which ADR-0209 §7 rules out.
- **Everyone signs in.** Showing a client or legal a change before it goes live is a main reason to preview.
- **Always the reviewer's view.** A guest's view catches a page guests will get as a 404, or a Collection with no Public
  Read.
- **An "all drafts" view.** It shows a site that will never exist, because drafts that don't ship together don't go live
  together.
- **Opening a Preview only once the Release could publish.** Review is where problems should come up, and the Preview is
  where reviewers look.
- **Layering scheduled Releases now.** It needs a rule for Releases that conflict, and nothing asks for it yet.
- **Forms that submit in a Preview.** Test submissions and visits would land in real data.
