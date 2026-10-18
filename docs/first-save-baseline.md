# First save of an existing entry

An existing entry with no versions, head or publication pin may open an editor without writing. The first null-base draft write takes the entry row lock, checks that history is still absent, snapshots and pins the server baseline, then writes the edited draft in the same transaction. First Publish uses that draft operation and publishes the returned version through the existing Release admission/review path. An entry with history and a missing or foreign pin is refused, not repaired by an editor write.

`EntryInitialBody` is the server authority: the stored body when present, otherwise the package default. `initialBody` supplied by a client is ignored. The baseline is never taken from the edited document. Compilation uses the normalized frozen snapshot; mirroring waits for commit. A failed initialization rolls back the particle, baseline and pin.

`resources/editor/default-trees.json` owns the static package defaults previously expressed in Beam JS `editor/defaults.ts`. The JS copy is consumed directly; run the paired `InitialBodyContractTest` with `EP_EDITOR_DEFAULTS_FIXTURE` pointing to its committed `beam-ux/src/editor/default-trees.json` to check byte parity. Hosts with their own defaults must bind `EntryInitialBody` on the server as well, rather than sending client baseline content. Existing client calls for entries with history still send the same version id.

This implements the L2 first-save ruling (2026-10-18): ADR-0225's one history and stale-base guard remain intact; mount is read-only; no extra Make editable step is introduced. Slug-collision handling for creating a new entry is unchanged.
