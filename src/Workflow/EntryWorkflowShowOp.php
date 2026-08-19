<?php

namespace Splicewire\Beam\Ux\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Data\WorkflowProjectionData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `POST /beam-ux-entry/{id}/op/workflow` — the entry-sheet Workflow tab's initial read: current
 * marking + the backend-computed available transitions, so the stepper never hardcodes a graph or a
 * button list. `OperationKind::Read` (a sync query, no state change) — the write half is
 * {@see EntryWorkflowTransitionOp}.
 *
 * Null when the entry is unmanaged (no `page`-type binding registered, or a non-`page` type) — the
 * SAME degrade {@see WorkflowActuator::projection()} already returns; a host wiring no binding at all
 * (the package default, {@see EntryPublishLifecycle}'s own documented "binding is a host decision")
 * gets a tab that renders "no workflow" rather than an error.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'workflow',
    kind: OperationKind::Read,
    model: BeamUxEntry::class,
    ability: 'ux.author',
    output: WorkflowProjectionData::class,
)]
class EntryWorkflowShowOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        $projection = app(WorkflowActuator::class)->projection($model);

        return $projection === null ? null : WorkflowProjectionData::from($projection);
    }
}
