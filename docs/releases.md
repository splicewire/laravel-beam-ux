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

Every member needs `ux.author` and the configured edit ability. Approval,
scheduling and publishing additionally check the publish ability on every member.
The defaults are `update` and `publish`, configurable under `beam.ux.releases`.
All checks repeat at execution, including a scheduled execution; they are not
frozen permissions. A Release cannot mix realms or database connections.

The ADRs declare a workflow but do not decide whether review is mandatory or
self-approval is allowed. Per the lead's EP-15 ruling, declared defaults are
`review_required=true`, `allow_self_approval=false`. Approval cannot be by the
creator or latest editor. Membership edits revoke approval. These defaults are
an owner-policy question, not a new ADR decision.

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
