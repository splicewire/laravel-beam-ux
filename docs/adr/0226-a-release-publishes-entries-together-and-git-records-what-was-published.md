# ADR-0226 — A Release publishes entries together, and git records what was published

Status: **accepted**. Decided in editor-parity ticket 06, 2026-10-14.
Date: 2026-10-14
Builds on: **ADR-0225** (an entry has one history; publish moves the pin), **ADR-0209 §11** and **ADR-0215** (the
particle is the source of record; a disk row re-asserts from its origin while pristine).
Amends: ADR-0225 decision 1, which named `GitVersionStore` as a possible driver under an entry's history.

## Context

Editor-parity ticket 03 (2026-10-12) found no commit or rollback action in any host. The Files page
(`MirrorStatusRowData`) is `readOnly: true` and compares git state only, so it can't see a file that disagrees with its
record. `rushing/laravel-versioning-git` ships a `GitVersionStore`, but its model is one branch of JSON snapshots per
record in a private repo under `storage/`, and `batchSnapshot` makes one commit per record. It also can't hold the
Publication Pin: its `Version` has no `id` and carries the SHA as `git_commit`, while `beam_ux_entries.published_version`
is a `uuid` column set from `$version->id` (`EntryPublication.php:512`; `GitVersionStore.php:311-322`).

ADR-0225 settled that only Publish writes the mirror, so the content tree only ever holds published bodies. Pending and
scheduled changes are drafts in the database. That makes git a record of publishing, not a place drafts could leak.

The owner's frame: git should ride the existing versioning and workflow substrate (`VersionStore`, the
`@splicewire/beam-versioning` panel, `@splicewire/beam-workflows`' scheduled transitions, the Frame resource and action
UI), for enterprise site editing where an author needs to see and schedule changes before they go live.

## Decision

1. **A Release is the unit of publishing.** A Release is a named set of entries, each fixed to one saved version, that
   goes live together. Publishing one entry is a Release of one. It is a `#[ParticleResource]` in this package, bound to
   a workflow (draft, in review, approved, scheduled, published). Scheduling is a scheduled `publish` transition through
   `@splicewire/beam-workflows`' `WorkflowActionForm`.
2. **Publishing a Release moves every member's Publication Pin in one transaction,** then mirrors and compiles each
   member, as ADR-0225 decision 3 does for one entry.
3. **Git records what was published.** Where the mirror disk sits inside a git working copy, publishing a Release makes
   **one commit** named for the Release, containing only its members' mirror files. The commit is built through a
   private index (`GIT_INDEX_FILE`), so a developer's staging area is never read or changed. Each published version
   records its commit SHA, and the versions panel shows it beside the row.
4. **A failed commit doesn't fail the publish.** The database is the authority. If git refuses (no repo, rebase in
   progress, detached HEAD, a failing hook), the Release still publishes, its versions record "not committed", and the
   Files page offers **Commit now**.
5. **Publishing doesn't push.** Push is `rushing/laravel-versioning-git`'s planned push layer, deferred with deployment.
   Committing from a server that isn't a working copy depends on that layer.
6. **Versions are fixed when an entry joins a Release.** Later saves don't slip in. "Use latest draft" swaps the
   version and returns an approved Release to review. Publishing is refused if any member's Publication Pin moved after
   it joined, with the choice "rebase onto theirs" or "drop the entry". That is ADR-0225 decision 6 applied to the
   whole Release. An entry may be in more than one open Release.
7. **Authority adds up from the members.** Anyone may assemble a Release of entries they can edit. Publishing it needs
   the publish ability on every member. Approval is the Release's workflow. The commit has no ability of its own. A
   tenant gets Releases with tenant-owned sites, but never commits: the mirror is the host repo's, so it belongs to the
   central realm.
8. **A rollback is a new Release** of the earlier versions. It rolls forward, so git gets a revert-like commit and no
   history is rewritten. There is no separate git rollback.
9. **Disk changes come back by hand, as drafts.** The Files page gains the state **differs from published**, which
   compares the file with the published body and not only with git. Per entry: **Take the file** saves it as a draft
   through the stale-save check, and **Re-mirror** rewrites the file from the published version. A pulled commit that
   touched several files offers **Take all as drafts**. Nothing is automatic, and Releases are never rebuilt from commit
   messages. ADR-0215's refresh-while-pristine rule for package and disk rows is unchanged.
10. **`GitVersionStore` is not an entry history driver.** Entries stay on the `VersionStore` the pin can hold. The
    doctor audit from ADR-0225 decision 2 refuses a host that binds `GitVersionStore` under entries.
    `GitVersionStore` stays available for other `Versionable` records.

## Consequences

- The Files page stops being read-only. **Commit now**, **Take the file**, **Re-mirror** and **Take all as drafts** are
  `#[ParticleOp]`s on `MirrorStatusRowData`, in the generic action UI.
- The version data gains an optional commit field, and `@splicewire/beam-versioning`'s panel shows it. No new history
  UI is built.
- Publish in the editor becomes "publish a Release of one", so one path serves the single entry and the batch.
- Previewing a Release before it goes live needs the public renderer to serve unpublished versions to reviewers. That
  is its own decision (editor-parity ticket 14).

## Considered and not taken

- **Git stores the history (`GitVersionStore` under entries).** Every draft becomes a commit on a hidden per-record
  branch of JSON nobody browses, and every Publish and Restore test has to cover a second backend. It also needs the pin
  widened to a string ref.
- **No commit in the app; developers commit the mirror themselves.** It leaves the Files page read-only and gives git no
  meaning an author chose.
- **Batch commit as "commit what's on disk now".** The commit would bundle whatever happened to be published before the
  click, so it stands for nothing an author decided.
- **A newer file wins automatically after a pull** (`update-from-newer` on, or a watcher). It reverses ADR-0209 §11.
