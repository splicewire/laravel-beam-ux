<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Database\Eloquent\Builder;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Recording\FilesRecordingReadPolicy;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;

#[ParticleResource(key: 'beam-ux-release-files', backing: BeamUxReleaseMember::class,
    data: ReleaseFilesData::class, input: false, policy: 'ux.author', readPolicy: FilesRecordingReadPolicy::class,
    label: 'Files recording', singularLabel: 'Release file', group: 'Content', section: 'authoring',
    readOnly: true, editable: false, deletable: false, showable: true)]
#[TypeScript, Title('Release file recording')]
final class ReleaseFilesData extends BeamData
{
    public function __construct(
        public string $id,
        #[Column(label: 'Release', sort: 0)] public string $release,
        #[Title('Release ID')] public string $releaseId,
        #[Column(label: 'File', sort: 1)] public string $entry,
        #[Title('Entry ID')] public string $entryId,
        #[Title('Saved version'), Column(label: 'Saved version', sort: 2)] public ?string $versionId,
        #[Title('Whole Release'), Column(label: 'Whole Release', sort: 3)] public string $members,
        #[Title('Local record'), Column(label: 'Local record', sort: 4)] public string $recordingStatus,
        #[Title('Commit SHA'), Column(label: 'Commit SHA', sort: 5)] public ?string $sha,
        #[Title('Files already matched')] public bool $noChange,
        #[Title('Recovery available')] public bool $canRetry,
        #[Title('Recording result')] public ?string $message,
    ) {}

    public static function scope(Builder $query): Builder
    {
        $actor = request()->user();
        $ids = [];
        if ($actor !== null) {
            foreach (BeamUxRelease::on($query->getModel()->getConnectionName())->where('status', 'published')->get() as $release) {
                try {
                    app(ReleaseRecording::class)->authorize($release, $actor);
                    $ids[] = $release->id;
                } catch (\Throwable) {
                    // An inaccessible member removes the entire Release, never just that member.
                }
            }
        }

        return $query->whereIn('release_id', $ids);
    }

    public static function project(BeamUxReleaseMember $model): self
    {
        $release = BeamUxRelease::on($model->getConnectionName())->findOrFail($model->release_id);
        $receipt = ReleaseRecordingData::project($release, request()->user());
        $entries = BeamUxEntry::on($model->getConnectionName())->whereKey($release->members()->pluck('entry_id'))->get()->keyBy('id');

        return new self($model->id, $release->title, $release->id, $entries[$model->entry_id]->slug,
            $model->entry_id, $model->version_id, $entries->pluck('slug')->implode(', '),
            $receipt->status, $receipt->sha, $receipt->noChange, $receipt->canRetry, $receipt->message);
    }
}
