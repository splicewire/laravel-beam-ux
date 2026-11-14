<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Http\Request;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Filters\FilterQuerySelection;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Ux\JsonDoc\NodeChildren;
use Splicewire\Beam\Ux\Localization\DiagnosedValidationException;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Shared save/publish admission. This validates declarations; it never reads Records. */
final class BindingBodyGuard
{
    public function __construct(private ProfileCollections $profiles, private BindingFieldValidator $fields) {}

    public function assert(BeamUxEntry $entry, array $body, array $profile, array $previous = [], ?array $previousRecordPage = null): void
    {
        if (! $this->hasBindings($body)) {
            return;
        }
        $stems = app(BindingFieldEvolution::class)->referencedStems($entry, $body);
        if ($stems !== []) {
            $connection = $entry->getConnection();
            if ($connection->transactionLevel() > 0
                && $connection->getSchemaBuilder()->hasTable(Beam::table('schemas'))) {
                // Publish/save already owns its entry rows. Share the exact native schema anchors
                // with registration before reading latest field/filter vocabulary (READ COMMITTED).
                foreach ($stems as $stem) {
                    $connection->table(Beam::table('schemas'))
                        ->where('schema_name', $stem)->orderBy('version')->orderBy('id')->lockForUpdate()->get();
                }
            }
        }
        $profile = $this->profile($entry, $profile);
        $declaration = $entry->getAttribute('record_page');
        $baseline = new BindingNodeBaseline($previous);
        $rootMatches = is_array($declaration) && $previousRecordPage !== null
            && ($declaration['collection'] ?? null) === ($previousRecordPage['collection'] ?? null);
        $this->fields->assert($body, $profile,
            fn ($scope, $relation) => $this->relation($scope, $relation),
            function ($path, $scope, $node, $location, $context) use ($entry, $baseline, $rootMatches) {
                $hasIndependentSource = isset($node['repeat']['collection'])
                    || array_filter($context, fn ($part) => isset($part['repeat']['collection'])) !== [];
                if ((! $hasIndependentSource && ! $rootMatches) || ! $baseline->unchanged($node, $location, $context)) {
                    return null;
                }

                return app(BindingFieldEvolution::class)->droppedScope($entry, $scope, $path);
            });
        $ids = [];
        $retainedQueries = [];
        BindingNodeBaseline::visit($body, function ($node, $path, $context) use ($baseline, &$retainedQueries) {
            if (isset($node['repeat'], $node['id']) && $baseline->unchanged($node, $path, $context)) {
                $retainedQueries[$node['id']] = true;
            }
        });
        $this->queries($body, $entry, null, $ids, $retainedQueries);
    }

    /** Refresh owner schemas only after admission holds the registration anchors. */
    private function profile(BeamUxEntry $entry, array $profile): array
    {
        $declaration = $entry->getAttribute('record_page');
        $offers = $profile['x-collections'] ?? [];
        foreach ($offers as &$offer) {
            $offer['source'] = $this->profiles->require($entry, $offer['name']);
            $offer['schema'] = $this->profiles->fieldSchema($offer['source']);
        }
        unset($offer);
        $recordScope = null;
        if (is_array($declaration)) {
            $source = $this->profiles->require($entry, $declaration['collection']);
            $recordScope = ['source' => $source, 'schema' => $this->profiles->fieldSchema($source), 'hasRecordPage' => true, 'recordPageRoot' => true];
        }

        return [...$profile, 'x-collections' => $offers, 'x-record' => $recordScope];
    }

    private function relation(array $scope, string $name): array
    {
        $source = $scope['source'];
        $stem = $source->address['collection'] ?? null;
        $edge = $stem === null ? null : (app(CollectionSchemaResolver::class)->resolve($stem)->relations()[$name] ?? null);
        if ($edge === null || ! $edge['many']) {
            $this->refuse(BindingError::NestedRepeatOwnerToMany);
        }
        $target = new CollectionSource($source->name, $source->resource, ['collection' => $edge['collection']]);

        return ['name' => $source->name, 'source' => $target, 'schema' => $this->profiles->fieldSchema($target), 'hasRecordPage' => false];
    }

    private function queries(array $nodes, BeamUxEntry $entry, ?CollectionSource $source, array &$ids, array $retainedQueries): void
    {
        foreach ($nodes as $node) {
            $current = $source;
            if (isset($node['repeat'])) {
                $repeat = $node['repeat'];
                if (array_diff(array_keys($repeat), ['collection', 'relation', 'query', 'empty'])) {
                    $this->refuse(BindingError::RepeatDeclarationFieldUnknown);
                }
                $id = $node['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($ids[$id])) {
                    $this->refuse(BindingError::RepeatIdRequired);
                }
                $ids[$id] = true;
                $current = $source === null
                    ? $this->profiles->require($entry, $repeat['collection'])
                    : $this->relation(['source' => $source], $repeat['relation'])['source'];
                $query = $repeat['query'] ?? [];
                $allowed = $source !== null || $current->parentEntryId !== null ? ['limit'] : ['filter', 'sort', 'limit', 'filterVariant', 'ids'];
                if (! is_array($query) || array_diff(array_keys($query), $allowed)) {
                    $this->refuse(BindingError::RepeatQueryFieldUndeclared);
                }
                // Hydrate independently of a JsonNode caller, so malformed limits never reach the query runtime.
                try {
                    RepeatQueryData::from($query);
                } catch (\InvalidArgumentException|\TypeError $e) {
                    throw DiagnosedValidationException::diagnosed('body', BindingError::RepeatQueryInvalid, $e);
                }
                if (isset($query['ids']) && ! isset($current->address['collection'])) {
                    $this->refuse(BindingError::RecordSelectionNeedsCollection);
                }
                $parameters = array_filter($query, fn ($value) => $value !== null);
                unset($parameters['limit'], $parameters['ids']);
                if ($parameters !== []) {
                    if (isset($retainedQueries[$id])) {
                        $parameters = app(BindingFieldEvolution::class)->queryForValidation($current, $parameters, $entry);
                    }
                    // The owner still checks every current/unknown alias, variant and access, even when all
                    // known-dropped aliases were removed from this validation-only copy.
                    app(BindingRequestScope::class)->run(app(Request::class), $current->address,
                        fn () => app(FilterQuerySelection::class)->validateParticle($current->resource, $parameters));
                }
                $this->queries($repeat['empty'] ?? [], $entry, $source, $ids, $retainedQueries);
            }
            $this->queries(NodeChildren::of($node), $entry, $current, $ids, $retainedQueries);
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
            if ($this->hasBindings(NodeChildren::of($node))) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, scalar>  $params */
    private function refuse(BindingError $error, array $params = []): never
    {
        throw CodedValidationException::for('body', $error, $params);
    }
}
