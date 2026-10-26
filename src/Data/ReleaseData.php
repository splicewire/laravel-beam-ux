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
        /** ADR-0232 d15: where a signed-in author opens this Release's Preview; null when it has nothing saved to render. */
        public ?string $previewHref = null,
        /** ADR-0232 d8: whether this reader may turn the Preview Links on or off and rotate them (edit on every member). */
        public bool $canManagePreviewLinks = false,
        /**
         * ADR-0236 d2: the Redirects the Release leaves. While it is open, the ones it would leave (no id yet); once
         * published, the ones it left, each with its id so an unwanted one is removed through the Redirect resource.
         *
         * @var list<array{id: ?string, path: string, match: string, to: ?string, origin: string}>
         */
        #[\Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType('Array<{ id: string | null; path: string; match: string; to: string | null; origin: string }>')]
        public array $redirects = [],
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
        $manages = $model->status !== 'published'
            && app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->mayManageLinks($model, request()->user());

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
            $model->getAttribute('preview_links_enabled') && $manages
                ? app(\Splicewire\Beam\Ux\Preview\ReleasePreviewLinks::class)->url($model) : null,
            self::previewHref($model, $members),
            $manages,
            self::redirects($model),
        );
    }

    /** @return list<array{id: ?string, path: string, match: string, to: ?string, origin: string}> */
    private static function redirects(BeamUxRelease $model): array
    {
        if (! \Illuminate\Support\Facades\Schema::connection($model->getConnectionName())->hasTable('beam_ux_redirects')) {
            return [];
        }
        if ($model->status === 'published') {
            return \Splicewire\Beam\Ux\Models\BeamUxRedirect::on($model->getConnectionName())->where('origin_ref', (string) $model->getKey())
                ->whereIn('origin', ['release', 'takedown'])->orderBy('path')->get()
                ->map(fn ($row) => ['id' => (string) $row->getKey(), 'path' => $row->path, 'match' => $row->match,
                    'to' => RedirectData::project($row)->target, 'origin' => $row->origin])->all();
        }

        return array_map(fn (array $row) => ['id' => null, 'path' => $row['path'], 'match' => $row['match'], 'to' => $row['to'],
            'origin' => $row['reason'] === 'takedown' ? 'takedown' : 'release'],
            app(\Splicewire\Beam\Ux\Redirect\ReleaseRedirects::class)->forRelease($model)['redirects']);
    }

    /** @param \Illuminate\Support\Collection<int, \Splicewire\Beam\Ux\Models\BeamUxReleaseMember> $members */
    private static function previewHref(BeamUxRelease $model, \Illuminate\Support\Collection $members): ?string
    {
        $name = config('beam.ux.route_name', 'beam.ux.').'site.preview.open';
        if (! in_array($model->status, \Splicewire\Beam\Ux\Release\ReleaseLifecycle::OPEN, true)
            || $members->whereNotNull('version_id')->isEmpty() || ! \Illuminate\Support\Facades\Route::has($name)) {
            return null;
        }

        return route($name, ['release' => $model->getKey()], false);
    }

    private static function filesHref(?string $member): ?string
    {
        return $member === null ? null : self::frameHref('beam-ux-release-files.edit', $member);
    }

    /**
     * A record's page on this host's Frame console, by its leaf's route name, or null where no realm mounts it. The
     * Preview bar links a Release's fixes to its page (`beam-ux-release.edit`) the same way.
     */
    public static function frameHref(string $leaf, string $id): ?string
    {
        foreach (array_keys(config('frame.realms', [])) as $realm) {
            try {
                $href = app(\Splicewire\Beam\Ux\Frame\RouteContextProjector::class)->hrefs($realm)[$leaf] ?? null;
                if (is_string($href)) {
                    return str_replace(':id', rawurlencode($id), $href);
                }
            } catch (\Throwable) {
                // A host without this optional seat gets no link rather than a broken one.
            }
        }

        return null;
    }
}
