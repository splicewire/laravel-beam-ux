# ADR-0243 — A Release member has a kind, and a Release's records are sinks

Status: **accepted**. Decided in config-sections ticket 04, 2026-11-09.
Date: 2026-11-09
Builds on: **ADR-0226** (a Release publishes entries together, and git records what was published), **ADR-0232**
(Take-downs; readiness), **ADR-0236** (Redirects).
Amends: ADR-0226 decisions 1, 3 and 7.

## Context

The config-sections map (laravel-beam) publishes config overrides as versioned rows, directly or inside a Release, and
in the central realm records the override file and any page files the same Release published in one commit (ticket 08).
A Release could not hold that: every member was a page-editor entry (`beam_ux_release_members.entry_id`, non-null), and
`ReleaseService` resolved, authorized, admitted, checked and published each member as an entry. The recording receipt's
rows were entry rows by assumption, under the mirror disk by assumption.

Ticket 04 is the prefactor: every Release publishes exactly as before, but what a member is and where a publication is
recorded become declared, registered seams.

## Decision

1. **A member has a kind.** `beam_ux_release_members` gains `member_kind` (default `entry`) and `member_ref`, the kind's
   own address for its subject. Existing members are backfilled as `entry`, their ref their `entry_id`, and `entry_id`
   becomes nullable, since another kind's member has no entry. A row written without the new columns (an older writer,
   a host's own insert) reads as an `entry` member addressed by its `entry_id`. `BeamUxRelease::members()` stays the
   entry members, so every entry reader (preview, readiness, redirects, Files) reads what it read; `allMembers()` reads
   every kind.
2. **A kind owns its subject; the Release owns what members share.** A `ReleaseMemberKind`, registered under
   `beam.ux.release-member-kinds` by its `member_kind`, resolves a ref (and refuses one outside the Release's realm and
   connection), authorizes it for edit, publish or a direct Release, admits it, says whether it moved after it joined,
   checks it is publishable, publishes the kind's members, and says what a rollback selects. The Release keeps its row
   lock, lifecycle, review, schedule and Preview Links, and drives every kind it holds through one transition. A member
   of a kind no installed package provides stops the Release: it is never skipped. A taken kind is never replaced
   (`Reject`), because stored rows name it. `entry` is `EntryMemberKind`, lifted out of `ReleaseService` unchanged;
   Take-downs and Redirects stay inside it.
3. **A Release's records are sinks.** A `PublishSink`, registered under `beam.ux.publish-sinks` by name, is a projection
   of the publication outside the database. The database pin is the publication, and is not a sink. A sink has two
   phases, and the registry owns the boundary of both. It **freezes** inside the publish transaction: handed each
   kind's `ReleaseProjection` in its own savepoint, it records what it will project. It **projects** after commit:
   the freeze returns that work as a closure, and the registry runs it once the publication commits, so nothing
   reaches a disk or a repository from a Release that rolled back. A sink may fail in either phase, and the registry
   contains it there: a failed freeze rolls back its savepoint alone, and a failed projection is reported while the
   next sink's still runs. A sink never fails a publication or stops another sink, and it never registers its own
   after-commit work, which would run outside that containment. `git` is the local recording of ADR-0226 decisions 3
   and 4, as `GitRecordingSink`: the recording freezes its receipt and returns its completion, unchanged.
4. **A recording row says its kind, ref and root.** Every manifest row in the frozen receipt carries the member's kind,
   its ref, and the root its `path` is relative to; an entry row's root is the mirror disk's. A receipt frozen before
   this decision reads as entry rows under the mirror disk, exactly as completion and retry read it before, so it still
   retries to the same commit. Local recording verifies entry rows under the mirror disk: a row of another kind, or
   under another root, refuses rather than being read as an entry, until that kind defines its rows (ticket 08).

ADR-0226 decision 1 reads "a named set of members, each of a kind"; decision 3's commit holds the Release's recorded
rows; decision 7's authority adds up over every member through its kind. Decision 7's tenant rule holds: recording
still refuses while tenancy is active.

## Consequences

- beam-config (ticket 08) adds a config-override kind and its rows to the same commit without editing `ReleaseService`.
- Every check a Release makes per member now goes through the kind registry, including recording and Preview Link
  authority. Readiness, preview and Files still read entry members, since only entries have a preview or a file today.
- A Release opens on an entry, and a rollback opens on the source's first entry member. A Release holding no entry
  member cannot yet be rolled back; ticket 08 decides that with the first non-entry kind.
- A built host bakes its registry index (`popcorn` bake); both new roots enter it from their `#[IsRegistry]`
  declarations, so a host that ships this version re-bakes.

## Considered and not taken

- **A nullable `entry_id` alone, with config members told apart by a null.** It names no kind, so a third kind would
  need another column, and nothing could refuse a kind no package provides.
- **Scoping `members()` by `member_kind`.** A members table that predates the columns (an install between deploy and
  migrate; a host or fixture that defines the table) would read no members at all. `entry_id IS NOT NULL` selects the
  same rows and reads both.
- **Rewriting frozen receipts to the new row shape in the migration.** A frozen receipt is never rewritten (its model
  refuses it); reading a missing kind as `entry` is what the receipt always meant.
- **The database pin as one sink among others.** A sink may fail; the pin may not. Making it a sink would put the
  publication itself behind a contract that tolerates failure.
