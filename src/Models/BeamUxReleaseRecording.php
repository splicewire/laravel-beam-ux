<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Frozen publication projection + local recording receipt; never an entry's version store. */
class BeamUxReleaseRecording extends Model
{
    use HasUuids;

    protected $table = 'beam_ux_release_recordings';

    protected $guarded = ['*'];

    protected $casts = ['manifest' => 'array', 'repository' => 'array', 'installation_started' => 'boolean', 'no_change' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            foreach (['id', 'release_id', 'manifest'] as $column) {
                if ($row->isDirty($column)) {
                    throw new \LogicException('A frozen recording manifest and identity cannot be rewritten.');
                }
            }
            if ($row->getOriginal('candidate') !== null || $row->getOriginal('sha') !== null) {
                foreach (['repository', 'branch', 'base', 'tree', 'preparation', 'candidate'] as $column) {
                    if ($row->isDirty($column)) {
                        throw new \LogicException('A saved recording candidate cannot be replaced.');
                    }
                }
            }
            if ($row->getOriginal('sha') !== null && $row->isDirty('sha')) {
                throw new \LogicException('A recorded SHA cannot be replaced.');
            }
            if ($row->getOriginal('installation_started') && $row->isDirty('installation_started')) {
                throw new \LogicException('Recording installation intent cannot be erased.');
            }
        });
    }
}
