<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Database\Eloquent\Builder;
use Splicewire\Beam\Collections\StandaloneRecordQuery;

/** Intersect the existing Collection read with a saved bounded selection before its normal read gates. */
final class RepeatSelectedRecords extends StandaloneRecordQuery
{
    /** @param list<string> $ids */
    public function __construct(private StandaloneRecordQuery $base, private array $ids) {}

    public function query(?string $connection = null): Builder
    {
        return $this->base->query($connection)->whereKey($this->ids);
    }
}
