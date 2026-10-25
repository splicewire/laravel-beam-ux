<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Database\Eloquent\Builder;
use Splicewire\Beam\Collections\StandaloneRecordQuery;

/** Narrow the backing BEFORE the canonical read guard and Public Read evaluator see it. */
final class RecordPageRecords extends StandaloneRecordQuery
{
    public function __construct(private StandaloneRecordQuery $base, private string $field, private string $value) {}

    public function query(?string $connection = null): Builder
    {
        return $this->base->query($connection)->where('payload->'.str_replace('.', '->', $this->field), $this->value);
    }
}
