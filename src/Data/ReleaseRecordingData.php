<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;

#[TypeScript, Title('Release recording')]
final class ReleaseRecordingData extends BeamData
{
    public function __construct(
        public string $releaseId,
        public string $status,
        public ?string $sha,
        public bool $noChange,
        public bool $canRetry,
        public ?string $message,
    ) {}

    public static function project(BeamUxRelease $release, ?Authenticatable $actor = null): self
    {
        $service = app(ReleaseRecording::class);
        $row = $service->receiptSummary($release);
        $allowed = false;
        if ($actor !== null) {
            try {
                $service->authorize($release, $actor);
                $allowed = $row !== null && ! in_array($row->state, ['recorded', 'unavailable'], true);
            } catch (\Throwable) {
                $allowed = false;
            }
        }
        $status = $release->status !== 'published' ? 'not-published' : ($row?->state ?? 'unavailable');
        $message = match ($status) {
            'not-published' => __('beam-ux::recording.status.not-published'),
            'recorded' => $row?->no_change ? __('beam-ux::recording.status.recorded-no-change') : __('beam-ux::recording.status.recorded'),
            'pending' => __('beam-ux::recording.status.pending'),
            'mirror-deleted' => __('beam-ux::recording.status.mirror-deleted'),
            'recovery-required' => __('beam-ux::recording.status.recovery-required'),
            'failed' => match ($row?->error) {
                'mirror-drift' => __('beam-ux::recording.status.failed.mirror-drift'),
                'superseded' => __('beam-ux::recording.status.failed.superseded'),
                'tenancy' => __('beam-ux::recording.status.failed.tenancy'),
                'mirror', 'completion' => __('beam-ux::recording.status.failed.mirror'),
                'path' => __('beam-ux::recording.status.failed.path'),
                default => __('beam-ux::recording.status.failed.default'),
            },
            'unavailable' => match ($row?->error) {
                'mirror-drift' => __('beam-ux::recording.status.unavailable.mirror-drift'),
                'mirror-disabled' => __('beam-ux::recording.status.unavailable.mirror-disabled'),
                'path' => __('beam-ux::recording.status.unavailable.path'),
                'manifest' => __('beam-ux::recording.status.unavailable.manifest'),
                default => __('beam-ux::recording.status.unavailable.default'),
            },
            default => __('beam-ux::recording.status.unavailable.default'),
        };

        return new self($release->id, $status, $row?->sha, $row?->no_change ?? false, $allowed, $message);
    }
}
