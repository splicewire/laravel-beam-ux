# EP-09 — Versioned page settings

ADR-0232 d1/d2 and ADR-0234 d6–d8/d10: the editor saves page settings with its body in the same particle version (`meta.pageSettings`). The entry row holds published copies. Restore restores metadata with the body into a draft; named Publish reads the named snapshot, validates its final URL, compiles, and only then writes the pin and copies.

Page settings include title, parent, segment, navigation order/group, layout/template, description, social image, Page Type and published date. First/latest publication timestamps are row audit copies. An explicitly supplied published date is preserved; an empty one defaults on Publish. Historical versions without page settings retain the current row copies rather than inventing historical values. The additive migration does not guess historical publication dates.

## EP-15 integration boundary

Lead ruling, 2026-10-18 00:49Z: `EntryUrlChanges::assertAllowed($context, $changes)` accepts a map of entry IDs to final `parentId`/`segment` values. It validates the final graph, including swaps, collisions, affected descendants and unpublished parents. It reuses `UrlResolver` for the existing absolute/relative segment grammar. Single Publish calls it today. EP-15 supplies its full batch before applying any Release moves, under the same connection transaction and lock ordering. This is validation only, not authorization or a batch-write API. No Release model, UI or route is introduced.

The check locks the connection's entry rows in ID order; author Publish takes the same ordered locks before the target entry lock. This favors correctness for the current page estate. No throughput claim is made.

## Contract and installation

Publish the `beam-ux-migrations` group and migrate the additive page-settings columns before consuming the feature. `scripts/export-page-settings.php` emits the TypeScript contract; `PageSettingsContractTest` regenerates and compares it with `tests/Fixtures/page-settings.ts`. Beam JS carries that generated file byte-identically. The existing body envelope carries working settings; entry-form updates of guest-visible page settings refuse with 422, while live fields remain writable. New page creation retains its desired URL in draft metadata, not on the published row.

System registration/seed publication keeps its existing contract. This slice does not rewrite legacy immutable versions or invent missing historical metadata.
