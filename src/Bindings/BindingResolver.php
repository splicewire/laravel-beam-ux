<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Data\RepeatRecordsData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** ADR-0229: saved declarations execute as this request's visitor, not their author. */
final class BindingResolver
{
    public function __construct(private PublishedBindingDocument $documents, private ProfileCollections $profiles) {}

    /** @return array<string, RepeatRecordsData> keyed by stable block identity */
    public function resolve(BeamUxEntry $entry, Request $request, string $realm): array
    {
        return $this->resolveDocument($entry, $this->documents->read($entry), $request, $realm);
    }

    /** Internal author-envelope path: the same reader, on the already authorized current body. */
    public function resolveDocument(BeamUxEntry $entry, array $body, Request $request, string $realm): array
    {
        $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser($request->user());
        $body = (new \Splicewire\Beam\Ux\Canvas\ViewGateFilter($gate))->filter($body);
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
                    throw ValidationException::withMessages(['repeat' => 'A Repeat requires a unique block id.']);
                }
                $out[$id] = $this->records($node, $entry, $request, $realm);
                if ($out[$id]->records === []) {
                    $this->walk($node['repeat']['empty'] ?? [], $entry, $request, $realm, $out);
                }
            } else {
                $this->walk(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node), $entry, $request, $realm, $out);
            }
        }
    }

    private function records(array $node, BeamUxEntry $entry, Request $request, string $realm): RepeatRecordsData
    {
        $repeat = $node['repeat'];
        $source = $this->profiles->require($entry, (string) ($repeat['collection'] ?? ''));
        $query = $repeat['query'] ?? [];
        if (! is_array($query) || array_diff(array_keys($query), ['filter', 'sort', 'limit', 'filterVariant'])) {
            throw ValidationException::withMessages(['repeat.query' => 'Unknown saved query field.']);
        }
        $limit = $query['limit'] ?? 25;
        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            throw ValidationException::withMessages(['repeat.query.limit' => 'The Repeat limit must be between 1 and 100.']);
        }
        unset($query['limit']);
        $context = null;
        $page = max(1, min(10000, (int) $request->query('repeat_'.$node['id'], 1)));
        // Duplicate creates new parameter bags. Never let a browser's filter or address override the saved declaration.
        if ($source->parentEntryId !== null) {
            if ($query !== []) {
                throw ValidationException::withMessages(['repeat.query' => 'This child-page source does not declare filters or sorts.']);
            }
            $rows = array_slice(app(ChildPageRecords::class)->read($source->parentEntryId, $realm, $request->user()), ($page - 1) * $limit, $limit + 1);
        } else {
            $resource = app(ParticleResourceRegistry::class)->get($source->resource);
            try {
                $rows = app(BindingRequestScope::class)->run($request, [...$query, ...$source->address],
                    function (Request $read) use ($resource, $realm, $limit, $page, &$context) {
                        $context = app(\Splicewire\Beam\Authorization\ResourceReadGuard::class)->bindReadContext($resource, $read);

                        return app(ParticleFrameResourceHandler::class)->forRequest($read, $realm)
                            ->indexWindow($resource->toResourceDefinition(), $limit + 1, ($page - 1) * $limit);
                    });
            } catch (AuthorizationException) {
                // Refused and empty have one public shape; no author fallback.
                $rows = [];
            }
        }
        $more = count($rows) > $limit;
        $records = $this->project($node, array_slice($rows, 0, $limit), $source, $request, true, $context);

        return new RepeatRecordsData($records, $more, $page);
    }

    /** Project each level as a batch; never resolve a relation inside an outer-record loop. */
    private function project(array $node, array $rows, CollectionSource $source, Request $request, bool $enriched, ?\Splicewire\Beam\Read\ReadContext $context): array
    {
        $fields = $this->fields($node);
        $records = array_map(function (array $row) use ($fields, $source): array {
            $visible = [];
            foreach ($fields as $field) {
                if (Arr::has($row, $field)) {
                    Arr::set($visible, $field, Arr::get($row, $field));
                }
            }
            // EP-23 owns Record Pages. Do not invent an editor URL for visitors.
            $visible['url'] = $source->parentEntryId !== null ? $row['url'] : null;

            return $visible;
        }, $rows);
        $nested = $this->nestedRepeats(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node));
        if ($nested === []) {
            return $records;
        }
        if (! isset($source->address['collection'])) {
            throw ValidationException::withMessages(['repeat.relation' => 'Nested Repeat requires an owner-declared Collection relation.']);
        }
        $definition = app(\Splicewire\Beam\Collections\CollectionSchemaResolver::class)->resolve($source->address['collection']);
        if (! $enriched && $rows !== []) {
            $relationData = app(\Splicewire\Beam\Collections\CollectionRelations::class)->resolve(
                array_map(fn ($row) => \Splicewire\Beam\Collections\CollectionRecordData::from($row), $rows), $request->user(), $context);
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
                throw ValidationException::withMessages(['repeat.relation' => 'Nested Repeat must name a declared to-many relation.']);
            }
            $query = $repeat['query'] ?? [];
            $limit = $query['limit'] ?? 25;
            if (! is_array($query) || array_diff(array_keys($query), ['limit']) || ! is_int($limit) || $limit < 1 || $limit > 100) {
                throw ValidationException::withMessages(['repeat.query' => 'A relation Repeat accepts only a limit between 1 and 100.']);
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
                    throw ValidationException::withMessages(['repeat' => 'A nested Repeat requires a unique block id.']);
                }
                $out[$id] = $node;
            } else {
                foreach ($this->nestedRepeats(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node)) as $id => $repeat) {
                    if (isset($out[$id])) {
                        throw ValidationException::withMessages(['repeat' => 'A nested Repeat requires a unique block id.']);
                    }
                    $out[$id] = $repeat;
                }
            }
        }

        return $out;
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
        foreach (\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node) as $child) {
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
