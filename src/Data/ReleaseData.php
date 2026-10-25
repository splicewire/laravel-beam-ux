<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
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
        #[Column(label: 'Title', sort: 0)]
        public string $title,
        #[Column(label: 'Realm', sort: 2)]
        public string $realm,
        #[Column(label: 'Status', sort: 1)]
        public string $status,
        public ?string $reviewedBy,
        #[DataCollectionOf(ReleaseMemberData::class)] public array $members,
        public ?WorkflowProjectionData $workflow,
        public bool $canSchedule,
        public bool $reviewRequired,
        public ?ReleaseRecordingData $recording = null,
        public ?string $filesMemberId = null,
        public ?string $filesHref = null,
        /** ADR-0232 d8: whether the Release's Preview Links are on. */
        public bool $previewLinksEnabled = false,
        /** A Preview Link minted for this read (7 days), for whoever may manage the links; never the secret. */
        public ?string $previewLink = null,
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
                $model->status !== 'published' && (! $entries->has($member->entry_id) || $entries[$member->entry_id]->published_version !== $member->observed_pin
                    || $entries[$member->entry_id]->workflow_marking !== $member->observed_marking)))->all(),
            $projection === null ? null : WorkflowProjectionData::from($projection),
            app()->bound(\Splicewire\Beam\Calendars\Actions\ActionService::class),
            (bool) config('beam.ux.releases.review_required', false),
            ReleaseRecordingData::project($model, request()->user()),
            $members->first()?->id,
            $model->status === 'published' ? self::filesHref($members->first()?->id) : null,
            (bool) $model->getAttribute('preview_links_enabled'),
            $model->getAttribute('preview_links_enabled') && app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->mayManageLinks($model, request()->user())
                ? app(\Splicewire\Beam\Ux\Preview\ReleasePreviewLinks::class)->url($model) : null,
        );
    }

    private static function filesHref(?string $member): ?string
    {
        if ($member === null) {
            return null;
        }
        foreach (array_keys(config('frame.realms', [])) as $realm) {
            try {
                $href = app(\Splicewire\Beam\Ux\Frame\RouteContextProjector::class)->hrefs($realm)['beam-ux-release-files.edit'] ?? null;
                if (is_string($href)) {
                    return str_replace(':id', rawurlencode($member), $href);
                }
            } catch (\Throwable) {
                // A host without this optional Files seat gets a status without a broken link.
            }
        }

        return null;
    }
}
