<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\LifecycleService;

/**
 * The REAL {@see EntryPublishGate} that lands in charter S6 — it replaces the S4/S5
 * {@see AlwaysPublishedGate} stub by reading the entry's persisted workflow marking (S6, ADR-0166 §4).
 * "Published-marking is EXACTLY what the sitemap / routing read for visibility."
 *
 * Two honest cases, both driven by the sibling `laravel-beam-workflows` engine:
 *
 *  1. **Unmanaged entry (no resolved binding).** A workflow is OPTIONAL: an entry whose `type` has no
 *     `Binding` on the {@see WorkflowBindingRegistry} has no state
 *     machine at all. It is treated as published — identical to the old stub, so wiring S6 never hides
 *     content that was visible before a host opts a type into a publish workflow.
 *  2. **Managed entry (a binding resolves).** Visibility is gated on the marking: the entry is public
 *     ONLY while its persisted `workflow_marking` sits at the published place
 *     ({@see BeamUxEntry::MARKING_PUBLISHED}). A draft/pending/rejected marking is not crawlable.
 *
 * Still a re-bindable port: a host whose publish lifecycle names its live place differently (or that
 * wants richer visibility logic) re-binds {@see EntryPublishGate} to its own implementation and every
 * consumer — {@see EntrySitemapSource}, routing — follows with no source-side change.
 *
 * **Composition seam (ADR-0092):** the persisted marking + this gate are beam-ux's; the
 * `LifecycleService::manages()` resolution it consults is the composed-down beam-workflows engine.
 */
class WorkflowMarkingPublishGate implements EntryPublishGate
{
    public function __construct(
        private LifecycleService $lifecycle,
    ) {}

    public function isPublished(BeamUxEntry $entry): bool
    {
        // No workflow governs this entry (unbound type) → optional-workflow fallback: published.
        if (! $this->lifecycle->manages($entry)) {
            return true;
        }

        // A workflow governs it → public only at the published marking.
        return $entry->workflow_marking === BeamUxEntry::MARKING_PUBLISHED;
    }
}
