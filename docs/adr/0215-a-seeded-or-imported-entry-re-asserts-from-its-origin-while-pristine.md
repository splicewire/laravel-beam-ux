# ADR-0215 — A seeded or imported entry re-asserts from its origin while pristine

Status: **accepted**, owner ruling 2026-10-05 ("recs": every recommendation in the tower specs accepted as
written; integrator relay 09:24Z). Decided in docs-walkthrough OQ-D3
(`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/tower/docs-walkthrough/SPEC.md`, decided at
splicewire-ecosystem `04de2be0`). Implemented by docs-walkthrough DOCS-06.
Date: 2026-10-05
Amends: **ADR-0209 §11** (mirror direction) and **ADR-0210 §6** (absence degrades loudly). Answers
beam-docs-satellite ticket 46's question "what re-asserts the OTB docs payload?".

## Context

ADR-0209 §11 gave the disk mirror a direction (the particle is the source of record and the disk file is
a projection of it) and drew one consequence from it: import "creates but never overwrites an existing
entry without `--force`". ADR-0210 §6 made every contributed seed row "site-owned from creation". The
code carries both as one rule, **create-once**:

- `SeedsEntries::seedPage()` returns an existing row at `(namespace, slug)` untouched ("already present ⇒
  nothing to do, not `updateOrCreate`", `src/Seed/SeedsEntries.php`).
- `RegisterEntriesFromDisk` skips any coordinate that already has a row (`src/Disk/RegisterEntriesFromDisk.php:186-196`).

Create-once is right for content a person authored in the CMS. It is wrong for content whose source is a
package stub or a file in git, because those sources change and the row never hears about it. The docs
walkthrough measured the result (FINDINGS 1, 6 and 11, coverage table in the SPEC): each host serves the
stub of the era in which it first seeded, and a stub nobody edited cannot be corrected by shipping a
better one. Two hosts on the same release show different landing pages because one installed earlier.

§11's escape hatch does not exist either. `splicewire:beam:ux:register-from-disk` takes `path`,
`--under`, `--type` and `--dry-run` (`src/Console/RegisterFromDiskCommand.php:39-42`). There is no
`--force`, so "without `--force`" names an option no one could have used.

## Decision

### 1. Every entry records its origin and what that origin last wrote

Two columns on `beam_ux_entries` (docs-walkthrough DM2):

- `origin`, one of three:
  - `package:<vendor/name>`: seeded by a package's `SeedStep`;
  - `disk:<path>`: imported from a file;
  - `cms`: authored in the editor.
- `asserted_hash`: the hash of the body and title the origin last wrote, taken **after** interpolation
  (so a seed-time fact that changes counts as a change).

### 2. Package and disk rows re-assert while pristine; edits are kept and reported

On seed or import, for a row whose origin is `package:` or `disk:`:

- **Pristine** (its current body+title hash equals `asserted_hash`): when the origin's hash has changed,
  the row is rewritten from its origin and `asserted_hash` moves with it.
- **Edited** (its hash differs from `asserted_hash`): the row is never overwritten. The doctor's
  `docs.diverged` audit reports it as a WARN, naming the origin and its version.

`cms` rows are never touched by a seed or an import.

**Placement is never re-asserted.** `segment`, `parent_id`, `access` and `workflow_marking` are site-owned
from creation whatever the origin, so a re-root, a renamed URL segment, an access change or a workflow
move survives every re-seed (the existing `laravel-beam-docs/tests/DocsSeedTest.php` segment assertions
stay green).

WARN rather than FAIL: whether a host edits a page is a host-dependent fact, and a check whose answer
depends on the host does not throw (the estate's rule, and ticket 46's own framing).

### 3. Precedence at one coordinate is declared

From strongest to weakest:

1. a CMS-edited row;
2. a host disk file;
3. a host published stub;
4. a package stub.

At seed, a pristine package row yields to a host file at the same coordinate (docs-walkthrough DOC-5).
A host file that collides with an **edited** row is a doctor FAIL naming both, because neither can win
silently.

### 4. §11's `--force` is withdrawn

No command offers an overwrite of an existing entry, and none is added. A pristine disk row now follows
its file through §2. An edited row is never overwritten, and the way to take a file's version over an
edit is to discard the edit in the editor, which makes the row pristine again.

## What stays from ADR-0209 §11 and ADR-0210 §6

- **The direction stands for CMS content.** The particle is the source of record for a `cms` row, the
  disk file is its projection, and a git edit to a CMS-owned page is still a visible no-op rather than a
  clobber. `UpdateFromNewer` remains the explicit opt-in for the other direction.
- **"Site-owned from creation" (0210 §6) now means placement**, plus any body the site has edited. A
  contributed row whose body nobody touched follows its package. Removing the contributor still leaves
  the row in place, and the rest of §6 (the inline "not installed" state, the doctor's orphan report,
  the headless skip) is unchanged.

## Consequences

- One release serves one docs era on every host that has not edited its pages. A better stub reaches every
  host on its next `splicewire:beam:seed`.
- Ticket 46's question is answered: seeding re-asserts the OTB docs payload, and `docs.diverged` (with
  DOCS-06's `docs.stale`) tells a host which rows it is holding back.
- The "edited" branch costs nothing today: there is no mdx edit form (beam-docs-satellite tickets 52 and
  54-B), so a docs row becomes edited only through the authoring ribbon or a direct particle write.
- How a row created before DM2 gets its `origin` is DOCS-06's to specify. Until a row has one, it is
  never re-asserted, so the migration alone changes no host.

## Alternatives rejected

- **Keep create-once and add `splicewire:beam:ux:reassert --write`** (the SPEC's "otherwise" column). The
  columns and audits would still land, but every host would drift until someone ran the command, and
  DOC-4 would shrink to "the audit names the stale rows".
- **Re-assert always** (D-A1 D5, "product pages equal their source"). It overwrites a host's edits, which
  is the clobber §11 was written to prevent. "Equal their source **or** reported diverged" keeps both.

## Records

Rule 11: grepped `ADR-0209 §11` and the create-once/`--force` claim (`creates but never overwrites`,
`without --force`, `create, never update`) across `.scratch`, counting instances:

- **7 instances in 4 files cite create-once or the nonexistent `--force` as current.** All are in
  `splicewire/laravel-beam/beam-docs-satellite/tickets/`:
  - `14-public-entry-renderer.md:130-131` (2);
  - `18-retire-beammdxshow-and-convert-the-app-tracks.md:324-325` (1);
  - `54-convert-the-app-using-build-tracks-to-entries.md:285` and `:964` (2);
  - `61-record-to-disk-writes-the-particle-stack-not-the-authored-mdx-so-nothing-says-the-file-is-a-fossil.md:179-180` (2).

  Each carries a pointer to this ADR.
- **3 instances cite only §11's direction, which stands for `cms` rows.** They are beam-docs-satellite
  `MAP.md:474`, ticket `60-…:132` and ticket `61-…:3`. They are unchanged.
- **3 instances are the measurements this decision rests on.** They are `splicewire/tower/docs-walkthrough/SPEC.md:46`
  and `:50`, and `sidebar/D-A2.md:57`. They are unchanged.
- **12 further `without --force` hits name other commands** (beam install's config publish, the dev DB
  guard, the schema freeze, `pnpm install`). They are not this claim.
