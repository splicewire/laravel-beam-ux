<?php

namespace Splicewire\Beam\Ux\Workflow;

use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\WorkflowMarkingPublishGate;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;

/**
 * The default publish lifecycle beam-ux ships for entries that a host opts INTO a publish workflow (S6).
 * It is a plain {@see WorkflowBlueprint} the sibling `laravel-beam-workflows` engine drives — beam-ux
 * builds NO new state machine; it merely names one shape and lets a host bind it (or bind its own).
 *
 *     draft ──publish──▶ published ──unpublish──▶ draft   (published ⇄ draft)
 *       │
 *       └─────archive───▶ archived                       (a sink)
 *
 * `published` is the sole crawlable place — {@see BeamUxEntry::MARKING_PUBLISHED} — which the
 * {@see WorkflowMarkingPublishGate} reads for visibility. No guards: the
 * transitions are always legal; a host layers guards/effects by editing the definition, not this shape.
 *
 * Binding is a HOST decision (the optional-workflow rule): beam-ux ships this blueprint + the type keys
 * but registers NO binding by default, so entries are unmanaged out of the box. A host binds a type
 * (`page`, …) to {@see DEFINITION} on the `WorkflowBindingRegistry` to govern it — mirroring how the app
 * binds `composition` / `anchor.review`.
 */
class EntryPublishLifecycle
{
    /** The definition lineage / blueprint key a binding points at. */
    public const DEFINITION = 'beam-ux.entry.publish';

    /** The initial (unpublished) place a bound entry starts at. */
    public const DRAFT = 'draft';

    public static function blueprint(): WorkflowBlueprint
    {
        return WorkflowBlueprint::fromArray([
            'name' => self::DEFINITION,
            'places' => [self::DRAFT, BeamUxEntry::MARKING_PUBLISHED, 'archived'],
            'initial' => [self::DRAFT],
            'transitions' => [
                ['name' => 'publish', 'from' => self::DRAFT, 'to' => BeamUxEntry::MARKING_PUBLISHED],
                ['name' => 'unpublish', 'from' => BeamUxEntry::MARKING_PUBLISHED, 'to' => self::DRAFT],
                ['name' => 'archive', 'from' => self::DRAFT, 'to' => 'archived'],
            ],
        ]);
    }
}
