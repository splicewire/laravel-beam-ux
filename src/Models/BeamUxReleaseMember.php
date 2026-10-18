<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Only fixed version addresses and publication observations; never a second body/history. */
class BeamUxReleaseMember extends Model
{
    use HasUuids;

    protected $table = 'beam_ux_release_members';

    protected $guarded = ['*'];
}
