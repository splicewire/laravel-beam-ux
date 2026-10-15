# ADR-0225 — An entry has one history: a save is a draft, and publish moves the pin

Status: **accepted**. Decided in editor-parity ticket 05, 2026-10-14.
Date: 2026-10-14
Builds on: **ADR-0214** (the entry-body transport is particle operations addressed by id) and **ADR-0127**
(`splicewire/splicewire-app docs/adr/0127-…`: the generic versions HTTP seam lives in `splicewire/laravel-beam-versioning`).
Pairs with: `splicewire/beam docs/adr/0004-editor-undo-is-a-local-session-and-history-is-server-versions.md` (the
editor half).

## Context

Editor-parity ticket 03 (2026-10-12) traced four history mechanisms for an entry and found no host that wires any of
them end to end:

- `EntryPublication` keeps two pins over `rushing/laravel-versioning`'s one `VersionStore` (`d13bb15`, 2026-09-12). Only
  the starters run it.
- The generic `beam-versioning` triplet (`Route::recordVersions`). The beam site's Undo and Versions tab use it. It knows
  nothing about entries, so a restore leaves the mirror file, the compiled artifact and the pin behind (F2).
- `RecordsRevisions` keeps revisions on the activity log. No write path calls it.
- `GitVersionStore` makes one commit per record. It is installed on the flagship and switched off.

`EntryPublication::recordsVersions()` treats both the versions table and `beam_ux_entries.published_version` as
optional. When either is missing, `save-body` publishes at once with no pin. The beam site has the table and lacks the
column, and its host then mints a version after the save has already compiled. Each artifact is filed under the
previous head, and the public page never shows the latest save (F1). Neither the beam site nor the flagship has the
column (F5).

## Decision

1. **`EntryPublication` is an entry's one history.** Every recorded save mints a version through the one `VersionStore`.
   `headVersion` (the working head) is what the author edits, and `publishedVersion` (the publication pin) is what a
   guest reads. `draftPending` is derived from the two and never stored. Git is not a second history and not its
   storage: ADR-0226 makes git the record of what a Release published.
2. **The pin is required wherever the editor is mounted.** The pin-less branch of `publishWritten()` is deleted. A host
   that mounts the editor without the versions table and the pin column fails at install, through a doctor audit and a
   boot or mount refusal that names `vendor:publish --tag=beam-migrations --tag=beam-ux-migrations`. It no longer
   degrades on each request. A host that doesn't mount the editor may still skip the schema.
3. **A save is a draft.** The editor's Save calls `recordDraft`: the working head moves and nothing public changes. **Publish**
   is a separate act with its own ability. It pins the version the author names and then mirrors and compiles. The
   mirror file and the compiled artifact follow `publishedVersion` only.
4. **Publishing a hidden workflow entry also makes it visible.** When the entry's type is bound to a lifecycle and its
   marking isn't `published`, Publish also fires the `publish` transition, under that transition's own guard. The pin and
   the marking stay separate: the marking decides whether the entry is visible, and the pin decides which body a
   visible entry serves. An `archived` entry stays hidden.
5. **Restore rolls forward into a draft.** It applies the frozen snapshot to the working copy and mints a new version. It
   does not publish, so rolling back the live page takes Restore and then Publish. This changes `restore()`, which
   currently publishes.
6. **A stale base is refused, not merged.** Save carries the `headVersion` its session started from. If the server's
   working head has moved, the save is refused with a conflict, and the author chooses between reloading theirs and
   saving theirs as a new version. Because Publish names its version, nobody can publish a draft they haven't seen.
   There are no locks.
7. **`save-body` is "record a draft, then publish", in one call,** for programmatic callers such as agents and scripts.
   The editor doesn't use it. It always mints a version and moves the pin.
8. **The activity log is the audit trail, not a history.** Save, Publish, Restore and Clear each write an activity row
   with a causer, in the activity log of the realm that owns the entry. Nothing restores from it. `RecordsRevisions` is
   not put on the entry write path.
9. **The generic triplet is not mounted for entries.** It stays the seam for records with no publication (ADR-0127).

## Consequences

- F1, F2, F5 and F6 have one fix: the beam site and the flagship adopt `EntryPublication` and run the pin migration.
  The beam site stops mounting `Route::recordVersions` for `beam-ux-entries`. Launch ticket 00 holds the routing.
- An entry that predates its first version still gets a baseline before its first draft (`baseline()`), so the first
  save appears in History and can be restored.
- Workflow `status` rows written by `LifecycleService` need a causer for decision 8 to hold (F8, in
  `splicewire/laravel-beam-workflows`).

## Considered and not taken

- **Publish on save, chosen per host or per realm.** It forks the one editor, and publish-on-save is the path that
  produced F1.
- **Undo walks server versions.** Unsaved work can't be undone, and every step costs a round trip and a version.
  Undo belongs to the editor (`splicewire/beam` ADR-0004).
- **Server autosave.** Saving the working copy without minting a version would let other authors see a body that
  `draftPending` can't. Autosave that mints versions floods History. It is deferred, not refused: it comes back if
  drafts must follow an author across devices.
- **Restore publishes.** That keeps the old behaviour, but breaks the rule that only Publish changes what a guest sees.
