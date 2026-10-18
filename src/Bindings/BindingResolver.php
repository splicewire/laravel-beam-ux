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
        $out = [];
        $this->walk($this->documents->read($entry), $entry, $request, $realm, $out);

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
            } else {
                $this->walk($node['children'] ?? [], $entry, $request, $realm, $out);
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
                    fn (Request $read) => app(ParticleFrameResourceHandler::class)->forRequest($read, $realm)
                        ->indexWindow($resource->toResourceDefinition(), $limit + 1, ($page - 1) * $limit));
            } catch (AuthorizationException) {
                // Refused and empty have one public shape; no author fallback.
                $rows = [];
            }
        }
        $more = count($rows) > $limit;
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
        }, array_slice($rows, 0, $limit));

        return new RepeatRecordsData($records, $more, $page);
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
        foreach ($node['children'] ?? [] as $child) {
            if (! isset($child['repeat'])) {
                $fields = [...$fields, ...$this->fields($child)];
            }
        }

        return array_values(array_unique($fields));
    }
}
