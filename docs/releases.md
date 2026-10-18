# Releases (EP-15)

ADR-0226 decisions 1, 2, 6–8 and ADR-0232 decisions 2–3 govern this surface.
`ReleaseData` is the read-only `beam-ux-release` ParticleResource. Its `command`
operation assembles fixed saved versions or Take-downs, rebases or drops members,
submits review, approves, publishes, schedules, or creates a rollback Release.
Generic resource writes cannot bypass the command service.

`EditorRoutes::mount()` mounts the existing entry operations plus `create-release`
and the Release index/show/command routes. Its optional second argument chooses
the Release URI. The default Inertia transport uses `/beam-ux-releases`.
Other hosts inject a matching transport. The package-owned `ReleasePanel` accepts
the server projection, saved-version choices, and that command transport.

The editor publish operation creates a Release of one and returns it on the
publication state. The Workflow tab's unpublish operation creates a Take-down
Release of one; while review is pending it reports that visibility has not moved.
System registration retains its separate, existing born-published contract.

## Admission and review

Every member needs `ux.author`. Beyond that, a Release checks an edit ability on every member, and approval,
scheduling and publishing check a publish ability. Owner ruling 2026-10-18 (launch ticket 00 `5dd27af98`): an upgrade
never makes Publish answer 403 to an author, so the abilities resolve as follows.

| `beam.ux.releases.*` | Checked |
|---|---|
| `edit_ability` / `publish_ability` set (for example `'update'`, `'publish'`) | Strictly, through the Gate, whatever defines it |
| unset (the default), and the host registered its own policy class for `BeamUxEntry` | That policy's `publish`, else its `update`, for publish; its `update` for edit |
| unset, and no host policy | Nothing beyond `ux.author` |

Two things do not count as the host defining an entry ability:
- a global `Gate::define()` for a common name such as `update`, because nothing can tell which subject it was
  written for (audiostud defines `update` for its own particles only);
- this package's own default policy, permission-cascade's `ConfiguredModelPolicy`, which BeamUxEntry's
  `#[UseCascadePolicy]` registers on every host.

The editor's Publish and the Workflow tab's Take-down make a Release of one (`ReleaseService::requestOne`), recorded
as `origin = direct` on the Release row. Every check on it decides on ONE ability for its entry, the publish ladder
(`publish`, else `update`, else `ux.author`): its creation and member, and, when review is opted in, its later
approval, scheduling and publication. A host policy that allows `publish` and denies `update` therefore publishes. A
Release built member by member (`origin = built`) keeps the separate edit check on its member operations and the publish
check on approval, scheduling and publication, and a member added by hand to a direct Release is an edit.

This is parity, not widening. Before Releases, the editor's Publish (and www's) was gated by `ux.author` alone. A host
that wants a stricter gate maps an ability or registers a policy. A policy the host declares is always respected: when
it denies, the Release is refused.

Review is OFF by default (`review_required = false`, `BEAM_UX_RELEASES_REVIEW_REQUIRED`): the editor's Publish is a
Release of one that publishes at once. A site opts in with `review_required = true`. A Release then waits for a
reviewer other than its creator or latest editor (`allow_self_approval = false`), and membership edits revoke
approval.

Pins and entry visibility are observed when membership is saved. A changed pin
or marking refuses publication until explicit rebase or drop. Versions never
silently follow a newer draft. Batch publication locks the entries in ID order,
validates the final URL graph once, and compiles every selected version before
changing any pin. The workflow transaction participant rolls back pins, marking
and workflow facts together. Mirrors run after commit. Open Releases retain
their referenced artifacts; rollback creates another reviewed Release.

A Take-down requires an available entry `unpublish` transition. An optional
redirect target must resolve to a different, published entry in the same realm,
and cannot be taken down by the same Release. This slice validates targets only;
EP-31 owns redirect emission. EP-16 owns git publication records and recovery.

## Optional scheduling

Calendars is suggested, and installed for development tests only. The server
projects `canSchedule` only when calendars' `ActionService` is bound. Without
that binding, schedule admission refuses with a clear message; publish-now works.
The UI uses the existing `WorkflowActionForm`, and the backend schedules through
the existing ActionService/WorkflowActionService. There is no second scheduler.

The scheduled action pins the Release revision. Rebase or membership changes
invalidate its token, so an old job cannot publish a newly reviewed revision.
Scheduling must use the current author's ActionContext principal. Calendar
delivery retries use the existing durable action runner.

## Delivery boundary

Package proofs exercise immediate and scheduled paths, fixed versions, review,
stale pins, compile refusal, authority revocation, rollback, retention, Take-down,
resource mounting, and theme draft/join. They do not claim a fresh host install,
Postgres contention proof, Release preview, git commits, or redirect emission.
