<?php

namespace Splicewire\Beam\Ux\Collections;

use Illuminate\Database\Eloquent\Builder;
use Splicewire\Beam\Collections\StandaloneRecordExclusion;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** ADR-0235 d3: an entry body never becomes a Collection Record, even when its owner is trashed. */
final class EntryBodyExclusion implements StandaloneRecordExclusion
{
    public function excludeFrom(Builder $query): void
    {
        // Use the current particle connection and include soft-deleted owners. This is ownership,
        // not the current actor's visibility of the entry, and never traverses another tenant.
        $bodies = $query->getConnection()->table((new BeamUxEntry)->getTable())
            ->whereNotNull('particle_id')->select('particle_id');
        $query->whereNotIn($query->getModel()->getQualifiedKeyName(), $bodies);
    }
}
