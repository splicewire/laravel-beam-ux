<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A hashed, one-use nonce for the tenant-only signed Release Preview handoff. */
final class BeamUxPreviewOpenNonce extends Model
{
    use HasUuids;

    protected $table = 'beam_ux_preview_open_nonces';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
