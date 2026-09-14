<?php

namespace Splicewire\Beam\Ux\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** An installed capability's mandatory entry constraint, independent of host permission grants. */
interface EntryRequirement
{
    public function allows(?Authenticatable $actor, BeamUxEntry $entry): bool;
}
