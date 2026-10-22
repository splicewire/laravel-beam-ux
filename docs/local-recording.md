# Local Release recording

ADR-0226 decisions 3–5 and 7 govern recording. Database versions and publication pins remain
authority; Git is a local record of published mirror projections, never the VersionStore.

ReleaseService's publish participant collects EntryPublication's fixed prepared snapshots.
The Release-only completion coordinator runs after outer commit, locks current entries, refuses
superseded pins/markings, completes and reads back every mirror, then records the whole Release.
Non-Release publication retains its existing callbacks. Optional failures are contained; pruning
is independent and later callbacks still run. Nonlocal mirrors keep their publication behavior
but do not get local Git recording.

Publish the new shared/create_beam_ux_release_recordings_table migration with beam-ux-migrations.
It adds one table only. Existing published Releases without a receipt remain unavailable for
recording; no automatic historical backfill occurs.

## Receipt protocol

BeamUxReleaseRecording is unique by Release. It freezes stable recording UUID and a bounded
4MiB binary-safe encoded projection manifest (entry/version/codec/path/mode/bytes/SHA256).
Its model refuses identity/manifest replacement. It stores repository/worktree identity,
attempt/preparation, expected parent/tree, candidate, installation intent and final SHA/no-change.
These are projection/recovery fields, not editable version snapshots.

Cooperating processes hold an OS lock on the canonical Git common directory. Native hooks,
identity and signing apply to private HEAD/index preparation. The recorder verifies full tree,
single expected parent, member-only delta, exact blobs/modes and recording identity. A durable
candidate anchor keeps the object available. Candidate persistence precedes installation intent;
intent precedes compare-and-swap of the captured branch. No command pushes.

A hook failure whose private HEAD still equals the stored base created no candidate and may
start a new serialized attempt at current HEAD. A saved/orphan candidate is never reparented.
After Git success/result-write failure, another process recovers the same candidate only if
reachable on the captured branch. If installation started but reachability is absent, duplicate
identity/tree mismatch/rewritten history or missing preparation is ambiguous and refuses.
A repeated terminal result verifies the same SHA. Already-equal member trees record baseSHA
with noChange:true and create no new commit.

The real index is neither read for commit content nor reconciled afterward. Its bytes and
unrelated staged/unstaged/untracked contents remain unchanged. An advanced HEAD can make
unchanged index entries reverse-stage MEMBER changes. Hooks can have their own filesystem
effects; recording does not sandbox host hooks.

## Admission and API

Recording reuses every member's direct/built publication decision through
ReleaseService::authorizeRecording. It adds no recording entitlement. An authenticated actor,
published immutable Release, all retained members, central residency and independently inactive
TenancyContext are required. Active/unknown tenancy refuses even a central-pinned model.

ReleaseRecordingData is the sanitized public status/SHA/noChange/canRetry/message contract.
ReleaseData includes it and a host-route-derived Files link. EntryVersionData projects a list
of Release references for each version; snapshots remain unchanged. The existing Theme,
window-mode and in-place history renderers consume those references; the generic beam-versioning
panel remains a separate adapter.

ReleaseFilesData is a distinct read-only beam-ux-release-files resource backed by Release members.
Its scope drops an entire Release when any member is inaccessible. FilesRecordingReadPolicy
does not widen global DiagnosticsReadPolicy. ReleaseFilesRecordOp declares Commit now as a stock
Frame action, with ReleaseRecordInputData selecting Release UUID and an addressed member check.
Failed retry uses 422 field feedback; publication itself remains successful. A recorded repeat
returns the same result. EditorRoutes::mount exposes its authenticated routes; hosts place the
resource in a realm and map a console path distinct from /beam-ux-release-files.

## Supported boundary

Enabled local mirrors in one normal/linked, born attached Git worktree are supported. Discovery
uses rev-parse, including .git files. Symlinks/escapes/submodules/nested repositories/duplicates/
case collisions, unsupported sparse/split/worktree config, locks and unfinished Git operations
refuse. Missing/drifted mirrors are never overwritten by retry. External branch advancement is
protected by CAS; concurrent external checkout/rebase/reset is unsupported and detected changes
refuse. A host may choose its Git executable via beam.ux.recording.git; no request can override it.

Take-down/null-ref/deletion/move recording remains unavailable while publication stays intact.
Canonical Theme import and missing-only Restore are available through the current-file panel.
Batch Take all, existing-file Restore overwrite, generic version panel adoption and remote push
remain broader EP-16 work. See the starter's docs/examples/service-desk-recording.md for the
fresh/upgrade and ordinary hook-failure/Files-retry journey. Focused tests and mounted UI/Git proof
are separate; existing full-suite compatibility limitations are not erased by this feature.

Current-pin comparison, bounded draft import and missing-only Restore are described in [Theme files](theme-files.md). Existing-file overwrite and broader EP16 reconciliation remain unfinished.
