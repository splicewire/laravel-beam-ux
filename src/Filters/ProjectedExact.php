<?php

namespace Splicewire\Beam\Ux\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Rushing\DataFilters\Operators\Exact;
use Spatie\QueryBuilder\AllowedFilter;
use Splicewire\Beam\Ux\Data\MirrorStatusRowData;
use Splicewire\Beam\Ux\Data\SitemapHealthRowData;

/**
 * Equality for the three status fields computed by the operational row projections.
 * Candidate rows are scanned before pagination; ordinary entry fields remain SQL filters.
 * Mirror projection keeps its existing read-through GitRepo cache refresh, never writes content.
 */
class ProjectedExact extends Exact
{
    public function toAllowedFilter(string $name, string $column): AllowedFilter
    {
        if (! in_array($column, ['state', 'published', 'indexed'], true)) {
            throw new InvalidArgumentException('ProjectedExact supports only operational status fields.');
        }

        return AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($name, $column): void {
            $values = is_array($value) ? $value : [$value];
            $expected = array_map(fn (mixed $item) => $this->normalize($item, $column, $name), $values);
            $ids = [];
            // Reorder only the scan clone: lazyById requires ID ordering, while the result
            // keeps the resource's declared ordering and pagination applies AFTER membership.
            foreach ((clone $query)->reorder()->lazyById(200) as $entry) {
                $row = $column === 'state'
                    ? MirrorStatusRowData::project($entry)
                    : SitemapHealthRowData::project($entry);
                if (in_array($row->{$column}, $expected, true)) {
                    $ids[] = $entry->getKey();
                }
            }
            $query->whereKey($ids);
        });
    }

    private function normalize(mixed $value, string $column, string $name): string|bool
    {
        if ($column === 'state' && is_string($value)) {
            return $value;
        }
        if ($column !== 'state') {
            if (in_array($value, [true, 1, '1', 'true'], true)) {
                return true;
            }
            if (in_array($value, [false, 0, '0', 'false'], true)) {
                return false;
            }
        }
        throw ValidationException::withMessages(['filter.'.$name => 'The filter value has an invalid type.']);
    }
}
