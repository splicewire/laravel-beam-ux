<?php

namespace Splicewire\Beam\Ux\Recording;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Release\Sinks\PublishSink;
use Splicewire\Beam\Ux\Release\Sinks\ReleaseProjection;

/**
 * The local Git recording of a Release (ADR-0226) as a sink: {@see ReleaseRecording} freezes the receipt inside the
 * publish transaction and returns its completion (mirror, Git, retention), which the registry runs after commit. It
 * projects entry members; another kind's rows join the same commit once that kind defines them (config-sections 08).
 */
class GitRecordingSink implements PublishSink
{
    public const NAME = 'git';

    public function name(): string
    {
        return self::NAME;
    }

    public function project(BeamUxRelease $release, ReleaseProjection $projection, Authenticatable $actor): ?\Closure
    {
        if ($projection->kind === BeamUxReleaseMember::KIND_ENTRY) {
            return app(ReleaseRecording::class)->prepare($release, $projection->published, $actor, $projection->withdrawn);
        }
        // config-sections 08: a config override's published roots join the SAME per-release receipt and commit (ADR-0243 d4).
        if ($projection->kind === BeamUxReleaseMember::KIND_CONFIG) {
            return app(ReleaseRecording::class)->prepareConfig($release, $projection->published, $actor);
        }

        return null;
    }
}
