<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;

#[TypeScript]
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
            'not-published' => 'Publication has not completed.',
            'recorded' => $row?->no_change ? 'Files already matched the parent commit; no new commit was created.' : 'Recorded locally.',
            'pending' => 'Recording is pending. Files can safely resume this same attempt.',
            'mirror-deleted' => 'The Release removed its mirror file and is finishing the same local Git attempt.',
            'recovery-required' => 'The recording result needs verification. Files can resume the saved attempt; no second commit is guessed.',
            'failed' => match ($row?->error) {
                'mirror-drift' => 'A file differs from this Release. Resolve it explicitly before retrying.',
                'superseded' => 'A newer publication superseded this mirror completion.',
                'tenancy' => 'Local recording is unavailable for this tenancy context.',
                'mirror', 'completion' => 'Publication succeeded. A mirror could not be completed or verified; no whole-Release Git commit was installed.',
                'path' => 'A mirror path could not be verified as a contained regular file.',
                default => 'Publication succeeded. Local recording failed; check mirror, Git, signing and hooks before retrying.',
            },
            'unavailable' => match ($row?->error) {
                'mirror-drift' => 'The published file differs from its mirror. The unknown mirror content was left untouched.',
                'mirror-disabled' => 'Local recording needs an enabled local mirror disk.',
                'path' => 'A mirror path could not be verified as a contained regular file.',
                'manifest' => 'A complete bounded recording manifest could not be frozen; no Git commit was attempted.',
                default => 'No supported local recording receipt is available for this Release.',
            },
            default => 'No supported local recording receipt is available for this Release.',
        };

        return new self($release->id, $status, $row?->sha, $row?->no_change ?? false, $allowed, $message);
    }
}
