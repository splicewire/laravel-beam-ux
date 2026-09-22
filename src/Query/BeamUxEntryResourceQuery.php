<?php

namespace Splicewire\Beam\Ux\Query;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Rushing\DataFilters\Query\ResourceQuery;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Orders entries by namespace and slug with a stable ID tie-break. The tie-break
 * follows an explicit requested sort so pagination remains deterministic.
 */
class BeamUxEntryResourceQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return ($this->definition->requireModel())::query();
    }

    protected function defaultSort(): ?string
    {
        return 'namespace';
    }

    public function applyTo(Builder $base, Request $request): QueryBuilder
    {
        // Spatie skips defaultSorts when an explicit sort is present. Keep the stable
        // ID tie-break after the requested sort, never ahead of it in the base query.
        return parent::applyTo($base, $request)
            ->defaultSorts('slug')
            ->orderBy('id');
    }
}
