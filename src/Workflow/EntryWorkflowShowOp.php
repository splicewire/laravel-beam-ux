<?php

namespace Splicewire\Beam\Ux\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Data\WorkflowProjectionData;

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
    // `ux.author` is an ENTITLEMENT key, not a policy verb, so the check is declared subject-free
    // (particle-operation-surface ticket 08). Until this was declared, the resolver was handed the
    // loaded entry and asked a per-action question with an entitlement token — and it answered
    // correctly only by accident: `BeamUxEntry` carries no policy in any host, so Laravel's Gate fell
    // through to the bare `ux.author` alias `beam-accounts` defines as
    // `fn ($user) => $user->can('entitlement:ux.author')`. Measured identical across 28 users in five
    // hosts, so the flip changed no answer — it removed the two accidents the right answer rested on.
    abilityModel: false,
    output: WorkflowProjectionData::class,
    // `input: false` — this operation accepts NO caller payload, declared rather than implied
    // (api-surface-coherence 68). Measured, not assumed: `handle()` never touches `$request`.
    // Enforced by `ParticleOperationController::rejectInput()`, so a request that carries one is
    // a 422 instead of a silent ignore.
    input: false,
)]
class EntryWorkflowShowOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        $projection = app(WorkflowActuator::class)->projection($model);

        return $projection === null ? null : WorkflowProjectionData::from($projection);
    }
}
