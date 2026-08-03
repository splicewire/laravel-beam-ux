<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **published-marking** half of the {@see EntrySitemapSource} gate: "is this
 * entry in a publicly-crawlable state?" (charter S6 — the workflow-marking aspect,
 * ADR-0166 §4).
 *
 * **Wired in S6.** {@see BeamUxEntry} now carries a `workflow_marking` column (the
 * optional beam-workflows subject envelope). The default binding
 * ({@see WorkflowMarkingPublishGate}) reads it: an unmanaged entry (no workflow
 * binding) counts as published, a managed entry is published only at its published
 * marking. A host may re-bind this port to its own visibility policy; every consumer
 * follows with no source-side change.
 */
interface EntryPublishGate
{
    public function isPublished(BeamUxEntry $entry): bool;
}
