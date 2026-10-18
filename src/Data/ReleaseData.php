<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Data\WorkflowProjectionData;

#[ParticleResource(key: 'beam-ux-release', backing: BeamUxRelease::class, input: false,
    label: 'Releases', group: 'Content', section: 'authoring', readOnly: true, policy: 'ux.author')]
#[TypeScript, Title('Release')]
final class ReleaseData extends BeamData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $realm,
        public string $status,
        public ?string $reviewedBy,
        #[DataCollectionOf(ReleaseMemberData::class)] public array $members,
        public ?WorkflowProjectionData $workflow,
        public bool $canSchedule,
        public bool $reviewRequired,
    ) {}

    public static function scope(Builder $query): Builder
    {
        return $query->whereNotNull($query->getModel()->getQualifiedKeyName());
    }

    public static function project(Model $model): self
    {
        /** @var BeamUxRelease $model */
        $members = $model->members()->orderBy('entry_id')->get();
        $entries = BeamUxEntry::on($model->getConnectionName())->whereKey($members->pluck('entry_id'))->get()->keyBy('id');
        $projection = app(WorkflowActuator::class)->projection($model);

        return new self($model->id, $model->title, $model->realm, $model->status, $model->reviewed_by,
            $members->map(fn ($member) => new ReleaseMemberData($member->entry_id, $member->version_id,
                $member->observed_pin, $member->redirect_target,
                ! $entries->has($member->entry_id) || $entries[$member->entry_id]->published_version !== $member->observed_pin
                    || $entries[$member->entry_id]->workflow_marking !== $member->observed_marking))->all(),
            $projection === null ? null : WorkflowProjectionData::from($projection),
            app()->bound(\Splicewire\Beam\Calendars\Actions\ActionService::class),
            (bool) config('beam.ux.releases.review_required', false),
        );
    }
}
