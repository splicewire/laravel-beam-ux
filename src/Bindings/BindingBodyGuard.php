<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Filters\FilterQuerySelection;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Shared save/publish admission. This validates declarations; it never reads Records. */
final class BindingBodyGuard
{
    public function __construct(private ProfileCollections $profiles, private BindingFieldValidator $fields) {}

    public function assert(BeamUxEntry $entry, array $body, array $profile): void
    {
        if (! $this->hasBindings($body)) {
            return;
        }
        $offers = $profile['x-collections'] ?? [];
        foreach ($offers as &$offer) {
            $offer['source'] = $this->profiles->require($entry, $offer['name']);
        }
        unset($offer);
        $this->fields->assert($body, [...$profile, 'x-collections' => $offers], fn ($scope, $relation) => $this->relation($scope, $relation));
        $ids = [];
        $this->queries($body, $entry, null, $ids);
    }

    private function relation(array $scope, string $name): array
    {
        $source = $scope['source'];
        $stem = $source->address['collection'] ?? null;
        $edge = $stem === null ? null : (app(CollectionSchemaResolver::class)->resolve($stem)->relations()[$name] ?? null);
        if ($edge === null || ! $edge['many']) {
            $this->refuse('A nested Repeat requires an owner-declared to-many relation.');
        }
        $target = new CollectionSource($source->name, $source->resource, ['collection' => $edge['collection']]);

        return ['name' => $source->name, 'source' => $target, 'schema' => $this->profiles->fieldSchema($target), 'hasRecordPage' => false];
    }

    private function queries(array $nodes, BeamUxEntry $entry, ?CollectionSource $source, array &$ids): void
    {
        foreach ($nodes as $node) {
            $current = $source;
            if (isset($node['repeat'])) {
                $repeat = $node['repeat'];
                if (array_diff(array_keys($repeat), ['collection', 'relation', 'query', 'empty'])) {
                    $this->refuse('Unknown Repeat declaration field.');
                }
                $id = $node['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($ids[$id])) {
                    $this->refuse('A Repeat requires a unique block id.');
                }
                $ids[$id] = true;
                $current = $source === null
                    ? $this->profiles->require($entry, $repeat['collection'])
                    : $this->relation(['source' => $source], $repeat['relation'])['source'];
                $query = $repeat['query'] ?? [];
                $allowed = $source !== null || $current->parentEntryId !== null ? ['limit'] : ['filter', 'sort', 'limit', 'filterVariant'];
                if (! is_array($query) || array_diff(array_keys($query), $allowed)) {
                    $this->refuse('This Repeat query uses an undeclared field.');
                }
                // Hydrate independently of a JsonNode caller, so malformed limits never reach the query runtime.
                try {
                    RepeatQueryData::from($query);
                } catch (\InvalidArgumentException|\TypeError $e) {
                    $this->refuse($e->getMessage());
                }
                $parameters = array_filter($query, fn ($value) => $value !== null);
                unset($parameters['limit']);
                if ($parameters !== []) {
                    // The owner's saved-filter validator owns names, types, variants and access. No second vocabulary.
                    app(BindingRequestScope::class)->run(app(Request::class), $current->address,
                        fn () => app(FilterQuerySelection::class)->validateParticle($current->resource, $parameters));
                }
                $this->queries($repeat['empty'] ?? [], $entry, $source, $ids);
            }
            $this->queries(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node), $entry, $current, $ids);
        }
    }

    private function hasBindings(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (isset($node['repeat']) || isset($node['when']) || isset($node['field'])) {
                return true;
            }
            foreach ($node['props'] ?? [] as $prop) {
                if (($prop['kind'] ?? null) === 'field') {
                    return true;
                }
            }
            if ($this->hasBindings(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node))) {
                return true;
            }
        }

        return false;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['body' => $message]);
    }
}
