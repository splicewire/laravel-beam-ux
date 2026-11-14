<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Collections\CollectionRecordData;
use Splicewire\Beam\Collections\CollectionRelations;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Collections\StandaloneRecordQuery;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Ux\Canvas\ViewGateFilter;
use Splicewire\Beam\Ux\Data\RepeatRecordsData;
use Splicewire\Beam\Ux\JsonDoc\NodeChildren;
use Splicewire\Beam\Ux\Localization\DiagnosedValidationException;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;

/** ADR-0229: declarations execute as the Preview viewer (guest unless a central reviewer opts in). */
final class BindingResolver
{
    public function __construct(private PublishedBindingDocument $documents, private ProfileCollections $profiles) {}

    /** @return array<string, RepeatRecordsData> keyed by stable block identity */
    public function resolve(BeamUxEntry $entry, Request $request, string $realm): array
    {
        $preview = $request->attributes->get('beam.ux.preview');
        if ($preview instanceof PreviewContext) {
            $request = clone $request;
            $request->setUserResolver(static fn () => $preview->viewer);
        }

        $body = $preview instanceof PreviewContext
            ? $this->documents->readPreview($entry, $preview)
            : $this->documents->read($entry);

        return $this->resolveDocument($entry, $body, $request, $realm);
    }

    /** Internal author-envelope path: the same reader, on the already authorized current body. */
    public function resolveDocument(BeamUxEntry $entry, array $body, Request $request, string $realm): array
    {
        $gate = app(Gate::class)->forUser($request->user());
        $body = (new ViewGateFilter($gate))->filter($body);
        $out = [];
        $this->walk($body, $entry, $request, $realm, $out);

        return $out;
    }

    private function walk(array $nodes, BeamUxEntry $entry, Request $request, string $realm, array &$out): void
    {
        foreach ($nodes as $node) {
            if (($node['kind'] ?? null) !== 'block') {
                continue;
            }
            if (isset($node['repeat'])) {
                $id = $node['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($out[$id])) {
                    throw CodedValidationException::for('repeat', BindingError::RepeatIdRequired);
                }
                $out[$id] = $this->records($node, $entry, $request, $realm);
                if ($out[$id]->records === []) {
                    $this->walk($node['repeat']['empty'] ?? [], $entry, $request, $realm, $out);
                }
            } else {
                $this->walk(NodeChildren::of($node), $entry, $request, $realm, $out);
            }
        }
    }

    private function records(array $node, BeamUxEntry $entry, Request $request, string $realm): RepeatRecordsData
    {
        $repeat = $node['repeat'];
        try {
            $source = $this->profiles->require($entry, (string) ($repeat['collection'] ?? ''));
        } catch (MissingProfileCollection) {
            // A held/missing Collection has no Records. Do not reinterpret access or backing failures as absence.
            return new RepeatRecordsData([], false, max(1, min(10000, (int) $request->query('repeat_'.$node['id'], 1))));
        }
        $query = $repeat['query'] ?? [];
        if (! is_array($query) || array_diff(array_keys($query), ['filter', 'sort', 'limit', 'filterVariant', 'ids'])) {
            throw CodedValidationException::for('repeat.query', BindingError::RepeatQueryFieldUnknown);
        }
        $limit = $query['limit'] ?? 25;
        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            throw CodedValidationException::for('repeat.query.limit', BindingError::RepeatLimitRange);
        }
        try {
            $query = app(BindingFieldEvolution::class)->query($source, $query, $entry);
        } catch (DroppedBindingField) {
            return new RepeatRecordsData([], false, max(1, min(10000, (int) $request->query('repeat_'.$node['id'], 1))));
        }
        try {
            $selection = RepeatQueryData::from($query)->ids;
        } catch (\InvalidArgumentException|\TypeError $refusal) {
            throw DiagnosedValidationException::diagnosed('repeat.query.ids', BindingError::RepeatSelectionInvalid, $refusal);
        }
        if ($selection !== null && ! isset($source->address['collection'])) {
            throw CodedValidationException::for('repeat.query.ids', BindingError::RecordSelectionNeedsCollection);
        }
        unset($query['limit'], $query['ids']);
        $context = null;
        $page = max(1, min(10000, (int) $request->query('repeat_'.$node['id'], 1)));
        // Duplicate creates new parameter bags. Never let a browser's filter or address override the saved declaration.
        if ($source->parentEntryId !== null) {
            if ($query !== []) {
                throw CodedValidationException::for('repeat.query', BindingError::ChildSourceNoFilters);
            }
            $rows = array_slice(app(ChildPageRecords::class)->read($source->parentEntryId, $realm, $request->user()), ($page - 1) * $limit, $limit + 1);
        } else {
            $resource = app(ParticleResourceRegistry::class)->get($source->resource);
            try {
                $rows = app(BindingRequestScope::class)->run($request, [...$query, ...$source->address],
                    function (Request $read) use ($resource, $realm, $limit, $page, &$context) {
                        $context = app(ResourceReadGuard::class)->bindReadContext($resource, $read);

                        return app(ParticleFrameResourceHandler::class)->forRequest($read, $realm)
                            ->indexWindow($resource->toResourceDefinition(), $limit + 1, ($page - 1) * $limit);
                    }, $selection === null ? null : new RepeatSelectedRecords(app(StandaloneRecordQuery::class), $selection));
            } catch (AuthorizationException) {
                // Refused and empty have one public shape; no author fallback.
                $rows = [];
            }
        }
        $more = count($rows) > $limit;
        $records = $this->project($node, array_slice($rows, 0, $limit), $source, $request, true, $context, $entry);

        return new RepeatRecordsData($records, $more, $page);
    }

    /** Project each level as a batch; never resolve a relation inside an outer-record loop. */
    private function project(array $node, array $rows, CollectionSource $source, Request $request, bool $enriched, ?ReadContext $context, ?BeamUxEntry $entry = null): array
    {
        $fields = $this->fields($node);
        $recordPage = $entry === null ? null : app(RecordPages::class)->pageFor($entry, $source);
        $aliases = app(BindingFieldEvolution::class)->fieldPaths($source, $fields, $entry);
        $records = array_map(function (array $row) use ($fields, $source, $recordPage, $aliases): array {
            $visible = [];
            foreach ($fields as $field) {
                $current = $aliases[$field];
                if ($current !== null && Arr::has($row, $current)) {
                    // Frozen compiled modules still name their original field. Supply it from the
                    // current declared field, through the owner's already-projected row.
                    Arr::set($visible, $field, Arr::get($row, $current));
                }
            }
            // EP-23 owns Record Pages. Do not invent an editor URL for visitors.
            $visible['url'] = $source->parentEntryId !== null ? $row['url'] : ($recordPage === null ? null : app(RecordPages::class)->url($recordPage, $row));

            return $visible;
        }, $rows);
        $nested = $this->nestedRepeats(NodeChildren::of($node));
        if ($nested === []) {
            return $records;
        }
        if (! isset($source->address['collection'])) {
            throw CodedValidationException::for('repeat.relation', BindingError::NestedRepeatNeedsRelation);
        }
        $definition = app(CollectionSchemaResolver::class)->resolve($source->address['collection']);
        if (! $enriched && $rows !== []) {
            $relationData = app(CollectionRelations::class)->resolve(
                array_map(fn ($row) => CollectionRecordData::from($row), $rows), $request->user(), $context);
            foreach ($rows as &$row) {
                $row['relations'] = $relationData[$row['id']] ?? [];
            }
            unset($row);
        }
        foreach ($nested as $id => $child) {
            $repeat = $child['repeat'];
            $relation = is_string($repeat['relation'] ?? null) ? $repeat['relation'] : '';
            $edge = $definition->relations()[$relation] ?? null;
            if ($edge === null || ! $edge['many'] || isset($repeat['collection'])) {
                throw CodedValidationException::for('repeat.relation', BindingError::NestedRepeatToManyRequired);
            }
            $query = $repeat['query'] ?? [];
            $limit = $query['limit'] ?? 25;
            if (! is_array($query) || array_diff(array_keys($query), ['limit']) || ! is_int($limit) || $limit < 1 || $limit > 100) {
                throw CodedValidationException::for('repeat.query', BindingError::RelationRepeatLimitRange);
            }
            $page = max(1, min(10000, (int) $request->query('repeat_'.$id, 1)));
            $flat = $windows = [];
            foreach ($rows as $index => $row) {
                // These values already came through the target's read/projection, not raw FK ids.
                $items = $row['relations'][$relation] ?? [];
                $window = array_slice($items, ($page - 1) * $limit, $limit + 1);
                $more = count($window) > $limit;
                $window = array_slice($window, 0, $limit);
                $windows[$index] = [count($flat), count($window), $more];
                foreach ($window as $item) {
                    $flat[] = is_array($item) ? $item : $item->toArray();
                }
            }
            $target = new CollectionSource($source->name, $source->resource, ['collection' => $edge['collection']]);
            $projected = $flat === [] ? [] : $this->project($child, $flat, $target, $request, false, $context);
            foreach ($windows as $index => [$offset, $count, $more]) {
                $records[$index]['bindings'][$id] = (new RepeatRecordsData(array_slice($projected, $offset, $count), $more, $page))->toArray();
            }
        }

        return $records;
    }

    private function nestedRepeats(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (isset($node['repeat'])) {
                $id = $node['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($out[$id])) {
                    throw CodedValidationException::for('repeat', BindingError::NestedRepeatIdRequired);
                }
                $out[$id] = $node;
            } else {
                foreach ($this->nestedRepeats(NodeChildren::of($node)) as $id => $repeat) {
                    if (isset($out[$id])) {
                        throw CodedValidationException::for('repeat', BindingError::NestedRepeatIdRequired);
                    }
                    $out[$id] = $repeat;
                }
            }
        }

        return $out;
    }

    /** Project the root Record scope; Repeat children have their own separately guarded scope. */
    public function projectRoot(array $body, array $row, array $additionalFields = [], ?CollectionSource $source = null, ?BeamUxEntry $entry = null): array
    {
        $fields = $this->fields(['children' => $body]);
        $visible = [];
        $fields = array_values(array_unique([...$fields, ...$additionalFields]));
        $aliases = $source === null ? array_combine($fields, $fields) : app(BindingFieldEvolution::class)->fieldPaths($source, $fields, $entry);
        foreach ($fields as $field) {
            $current = $aliases[$field];
            if ($current !== null && Arr::has($row, $current)) {
                Arr::set($visible, $field, Arr::get($row, $current));
            }
        }
        if (isset($row['url'])) {
            $visible['url'] = $row['url'];
        }

        return $visible;
    }

    /** The resolver projects only referenced fields; nested repeat scopes are resolved separately. */
    private function fields(array $node): array
    {
        $fields = [];
        if (is_string($node['field'] ?? null)) {
            $fields[] = $node['field'];
        }
        foreach (['all', 'any'] as $join) {
            foreach ($node['when'][$join] ?? [] as $rule) {
                if (is_string($rule['field'] ?? null)) {
                    $fields[] = $rule['field'];
                }
            }
        }
        foreach ($node['props'] ?? [] as $prop) {
            if (($prop['kind'] ?? null) === 'field' && is_string($prop['value'] ?? null)) {
                $fields[] = $prop['value'];
            }
        }
        foreach (NodeChildren::of($node) as $child) {
            if (! isset($child['repeat'])) {
                $fields = [...$fields, ...$this->fields($child)];
            } else {
                // An inner Repeat's empty state uses the enclosing Record, not its absent target.
                foreach ($child['repeat']['empty'] ?? [] as $empty) {
                    if (! isset($empty['repeat'])) {
                        $fields = [...$fields, ...$this->fields($empty)];
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }
}
