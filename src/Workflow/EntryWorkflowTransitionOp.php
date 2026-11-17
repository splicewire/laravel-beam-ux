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
 * `blockers` on the response, HTTP 200, same as every other read of this entry. The exceptions are the
 * Release-routed moves (a Take-down, and under review a Publish: launch ticket 75): a request with no
 * actor is refused 403, as any Release request is, and a Publish with no pinned body 422.
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
                return new TransitionResult([], 'unpublish', false, [\Splicewire\Beam\Ux\Release\ReleaseError::TakeDownUnavailable->message()]);
            }
            if (! $actor instanceof \Illuminate\Contracts\Auth\Authenticatable) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }

            return app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->requestOne($model, null, $actor);
        }

        // Launch ticket 75: under review, a move INTO the published place is a Publish like any other and goes through a
        // Release. Fired on the actuator it would re-show the entry's pinned body with no review, and the live state it
        // overturns can be a Take-down a reviewer approved. Decided by the transition's TARGET, not its name, so a
        // host-bound definition's other way in (`restore: archived -> published`) cannot slip past; and availability
        // is read with the SAME actor context the actuator would fire with, so an actor-guarded `publish` is judged as
        // it would apply. Under review such a move never reaches the actuator:
        //  - `publish`, when available, becomes a direct Release of the entry's CURRENT pin, submitted (`in_review`);
        //    only its approval and publish re-mark the entry published, at that pin (EntryPublication::publishVersions);
        //  - `publish` unavailable, or any other transition into `published`, is refused, as a Take-down is.
        // Review off: the actuator fires every transition, unchanged.
        if (app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->reviewRequired()) {
            $refusal = self::reviewedPublish($model, $input->transition, $actor);
            if ($refusal !== false) {
                return $refusal;
            }
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
                $payload->status === 'published' ? [] : [($payload->members()->whereNull('version_id')->exists() ? 'Take-down' : 'Publish').' submitted for Release review: '.$payload->id],
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
    /**
     * Under review: `false` when `$transition` does not move the entry into the published place (the actuator may fire
     * it); otherwise the outcome that replaces firing it (a Release of the current pin, or a refusal).
     */
    private static function reviewedPublish(Model $model, string $transition, mixed $actor): TransitionResult|\Splicewire\Beam\Ux\Models\BeamUxRelease|false
    {
        $workflow = app(WorkflowActuator::class);
        $context = new TransitionContext(actor: self::actorToken($actor));
        $projection = $workflow->projection($model, $context);
        $declared = collect($projection['transitions'] ?? [])->firstWhere('name', $transition);
        if ($declared === null || ! in_array(\Splicewire\Beam\Ux\Models\BeamUxEntry::MARKING_PUBLISHED, (array) $declared['to'], true)) {
            return false;
        }
        if ($transition !== 'publish' || ! in_array($transition, $projection['available'] ?? [], true)) {
            $reason = is_string($declared['reason'] ?? null) ? $declared['reason'] : \Splicewire\Beam\Ux\Publish\EntryError::PublishRefused->message();

            return new TransitionResult([], $transition, false, [$reason]);
        }
        if (! $actor instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        /** @var \Splicewire\Beam\Ux\Models\BeamUxEntry $entry */
        $entry = $model->fresh() ?? $model;
        $pin = $entry->published_version;
        if ($pin === null) {
            // No pinned body to re-show, and a null ref would request a Take-down: refuse rather than guess.
            throw \Splicewire\Beam\Localization\CodedValidationException::for('transition', \Splicewire\Beam\Ux\Publish\EntryError::PublishRefused);
        }

        return app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->requestOne($entry, (string) $pin, $actor);
    }

    private static function actorToken(mixed $actor): ?string
    {
        return $actor instanceof Model ? 'user:'.$actor->getKey() : null;
    }
}
