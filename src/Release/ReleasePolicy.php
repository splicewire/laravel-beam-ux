<?php

namespace Splicewire\Beam\Ux\Release;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Support\EditorAuthorizer;

/**
 * Who may read Releases: anyone who can author, the rule the Releases resource declares (`ux.author`) and ADR-0226 d7's
 * "anyone who can edit the members may assemble one". The list asked only that; opening one asked the model's `view`,
 * and with no policy that refused every author (the EP-15 receipt, 2026-10-25). Both now ask the same question.
 *
 * Reading is all this governs. Assembling, reviewing and publishing are ReleaseService's, member by member.
 */
final class ReleasePolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return app(EditorAuthorizer::class)->allows($user);
    }

    public function view(?Authenticatable $user, BeamUxRelease $release): bool
    {
        return app(EditorAuthorizer::class)->allows($user);
    }
}
