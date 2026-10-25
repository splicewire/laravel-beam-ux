<?php

namespace Splicewire\Beam\Ux\Release;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Models\BeamUxRelease;

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
        return Gate::forUser($user)->allows('ux.author');
    }

    public function view(?Authenticatable $user, BeamUxRelease $release): bool
    {
        return Gate::forUser($user)->allows('ux.author');
    }
}
