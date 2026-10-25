<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A revocable, hashed bearer grant for one saved entry version or one Release. */
class BeamUxPreviewGrant extends Model
{
    use HasUuids;

    protected $table = 'beam_ux_preview_grants';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(BeamUxRelease::class, 'release_id');
    }
}
