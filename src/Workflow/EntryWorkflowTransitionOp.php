<?php

namespace Splicewire\Beam\Ux\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\TransitionResult;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Data\WorkflowTransitionAttemptData;
use Splicewire\Beam\Workflows\Data\WorkflowTransitionRequestData;

/**
 * `POST /beam-ux-entry/{id}/op/transition` — the entry-sheet Workflow tab's transition button, the
 * FIRST write particle in this effort (operator-surface-prototypes, Direction B). Fires
 * `WorkflowActuator::transition()`; `respond()` re-projects the refreshed marking so the stepper
 * updates its available-transitions button set in the SAME round trip, whether the attempt applied
 * or not.
 *
 * Never throws on a rejected transition (illegal/guarded/unmanaged) — {@see TransitionResult}'s own
 * design is a returned outcome, not an exception, so `handle()` follows that: `applied: false` +
 * `blockers` on the response, HTTP 200, same as every other read of this entry.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'transition',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Entitlement plane, subject-free — same reasoning as {@see EntryWorkflowShowOp}'s own note
    // (particle-operation-surface ticket 08). The two ops MUST stay on the same plane: a Workflow tab
    // that can read the projection but not fire the transition it just rendered is worse than either
    // gate alone.
    abilityModel: false,
    input: WorkflowTransitionRequestData::class,
    output: \Splicewire\Beam\Ux\Data\EntryWorkflowAttemptData::class,
)]
class EntryWorkflowTransitionOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        $input = WorkflowTransitionRequestData::from($request->all());

        if ($input->transition === 'unpublish') {
            $workflow = app(WorkflowActuator::class);
            if (! in_array('unpublish', $workflow->available($model), true)) {
                return new TransitionResult([], 'unpublish', false, ['Take-down is not available from this entry state.']);
            }
            if (! $actor instanceof \Illuminate\Contracts\Auth\Authenticatable) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }

            return app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->requestOne($model, null, $actor);
        }

        return app(WorkflowActuator::class)->transition(
            $model,
            $input->transition,
            new TransitionContext(actor: self::actorToken($actor)),
        );
    }

    public static function respond(mixed $payload, Model $model): mixed
    {
        $projection = app(WorkflowActuator::class)->projection($model->fresh());
        if ($payload instanceof \Splicewire\Beam\Ux\Models\BeamUxRelease) {
            $response = new \Splicewire\Beam\Ux\Data\EntryWorkflowAttemptData(
                $payload->status === 'published',
                $payload->status === 'published' ? [] : ['Take-down submitted for Release review: '.$payload->id],
                $projection === null ? null : \Splicewire\Beam\Workflows\Data\WorkflowProjectionData::from($projection),
            );
            $response->release = \Splicewire\Beam\Ux\Data\ReleaseData::project($payload);

            return $response;
        }

        /** @var TransitionResult $payload */
        return WorkflowTransitionAttemptData::fromResult(
            $payload,
            app(WorkflowActuator::class)->projection($model->fresh()),
        );
    }

    /**
     * The opaque `kind:selector` token beam-workflows carries through the transition. The engine
     * never dereferences it — identity is resolved by the service that owns its users — so this is a
     * stamp, not a lookup. Matches `SubmitTrainingRunOp`'s own derivation exactly.
     */
    private static function actorToken(mixed $actor): ?string
    {
        return $actor instanceof Model ? 'user:'.$actor->getKey() : null;
    }
}
