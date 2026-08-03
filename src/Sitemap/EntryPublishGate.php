<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **published-marking** half of the {@see EntrySitemapSource} gate: "is this
 * entry in a publicly-crawlable state?" (charter S6 — the workflow-marking aspect,
 * ADR-0166 §4).
 *
 * **S6 not yet wired.** BeamUxEntry does not carry a workflow marking column yet
 * (that aspect lands in charter S6). Until then the default binding
 * ({@see AlwaysPublishedGate}) stubs this to `true` — every entry counts as
 * "published". When S6 wires a real marking onto the entry, re-bind this port to
 * read it; every consumer follows with no source-side change.
 */
interface EntryPublishGate
{
    public function isPublished(BeamUxEntry $entry): bool;
}
