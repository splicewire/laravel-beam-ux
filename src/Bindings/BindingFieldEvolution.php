<?php

namespace Splicewire\Beam\Ux\Bindings;

use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Collections\CollectionDefinition;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Read-time/next-save projection only. No frozen artifact, published snapshot or unrelated value is written. */
final class BindingFieldEvolution
{
    public function __construct(private ProfileCollections $profiles, private ?SchemaRegistry $registry = null) {}

    public function history(string $stem): array
    {
        $versions = [];
        if ($this->registry === null) {
            return [];
        }
        $ids = $this->registry instanceof \Schemastud\DataSchemas\Contracts\EnumeratesVersions
            ? array_map(fn (int $version) => $stem.'/'.$version, $this->registry->versionsFor($stem))
            : $this->registry->ids();
        foreach ($ids as $id) {
            try {
                $parsed = SchemaId::from($id);
            } catch (InvalidArgumentException) {
                continue; // An unrelated legacy identity cannot invalidate this stem's declared history.
            }
            if ($parsed->stem() === $stem && $parsed->version() !== null) {
                $schema = $this->registry->get($id);
                if ($schema === null) {
                    throw new InvalidArgumentException('A registered schema version is unavailable.');
                }
                $versions[$parsed->version()] = $schema;
            }
        }
        ksort($versions, SORT_NUMERIC);

        return array_values($versions);
    }

    /** A registered schema's field values; unknown keys stay visible to the existing validation. */
    public function pageFields(string $stem, array $fields, bool $dropRemoved = false): array
    {
        $history = $this->history($stem);
        if ($history === []) {
            return $fields;
        }
        $renames = new DeclaredFieldRenames($history);
        $declared = [];
        foreach ($history as $schema) {
            $declared += $schema['properties'] ?? [];
        }
        $out = [];
        foreach ($fields as $name => $value) {
            $current = isset($declared[$name]) ? $renames->property($name) : $name;
            if ($current === null) {
                if ($dropRemoved) {
                    continue; // Historical reads see a known drop as empty. New writes still refuse it.
                }
                $current = $name;
            }
            if (array_key_exists($current, $out) && $out[$current] !== $value) {
                throw new InvalidArgumentException('An old and renamed field both supply different values.');
            }
            $out[$current] = $value;
        }

        return $out;
    }

    public function document(BeamUxEntry $entry, array $body): array
    {
        if (! \Splicewire\Beam\Ux\Codec\JsonDocShape::is($body)) {
            return $body;
        }
        $sources = [];
        foreach ($this->profiles->sources($entry) as $name => $source) {
            $sources[$name] = $this->stem($entry, $source);
        }
        $declaration = $entry->getAttribute('record_page');
        $scope = is_array($declaration) ? ($sources[$declaration['collection']] ?? null) : null;

        $histories = [];
        try {
            return $this->walk($body, $sources, $scope, $histories);
        } catch (InvalidArgumentException|\TypeError $e) {
            throw \Splicewire\Beam\Ux\Localization\DiagnosedValidationException::diagnosed('body', BindingError::BodyBindingsUncheckable, $e);
        }
    }

    public function fieldPath(CollectionSource $source, string $path, ?BeamUxEntry $entry = null): ?string
    {
        return $this->fieldPaths($source, [$path], $entry)[$path];
    }

    /** Resolve a projection once per source, never once per Record/field. */
    public function fieldPaths(CollectionSource $source, array $paths, ?BeamUxEntry $entry = null): array
    {
        $stem = $source->address['collection'] ?? ($entry === null ? null : $this->stem($entry, $source));
        $history = $stem === null ? [] : $this->history($stem);
        $evolution = $history === [] ? null : new DeclaredFieldRenames($history);
        $out = [];
        foreach ($paths as $path) {
            if ($evolution === null || ! str_starts_with($path, 'fields.')) {
                $out[$path] = $path;

                continue;
            }
            $field = $evolution->field(substr($path, 7));
            $out[$path] = $field === null ? null : 'fields.'.$field;
        }

        return $out;
    }

    /** A known missing field's last frozen declaration; never an invented type or a renamed alias. */
    public function droppedScope(BeamUxEntry $entry, array $scope, string $path): ?array
    {
        $source = $scope['source'] ?? null;
        if ((! $source instanceof CollectionSource && ! isset($scope['schemaStem'])) || ! str_starts_with($path, 'fields.')) {
            return null;
        }
        $stem = $scope['schemaStem'] ?? $this->stem($entry, $source);
        $history = $stem === null ? [] : $this->history($stem);
        if (count($history) < 2) {
            return null;
        }
        $latest = $history[array_key_last($history)];
        if (($scope['schema']['properties']['fields'] ?? null) !== $latest) {
            return null; // A different host target/field projection is not a proved registry drop.
        }
        $current = (new DeclaredFieldRenames($history))->field(substr($path, 7));
        if ($current !== null && $current !== substr($path, 7)) {
            return null; // A declared rename follows its destination; it cannot become a drop exception.
        }
        array_pop($history); // A fallback must be historical, never the current declaration itself.
        foreach (array_reverse($history) as $schema) {
            $candidate = [...$scope, 'schema' => ['type' => 'object', 'properties' => ['fields' => $schema],
                '$defs' => $schema['$defs'] ?? [], 'definitions' => $schema['definitions'] ?? []]];
            try {
                // Use the SAME resolver, including local-reference checks, as ordinary admission.
                (new BindingFieldValidator)->declaredField($path, $candidate);

                return $candidate;
            } catch (MissingBindingField) {
                // Older artifacts may not yet declare this exact nested field.
            }
        }

        return null;
    }

    public function query(CollectionSource $source, array $query, ?BeamUxEntry $entry = null): array
    {
        $stem = $source->address['collection'] ?? ($entry === null ? null : $this->stem($entry, $source));

        return $stem === null ? $query : (new DeclaredFieldRenames($this->history($stem)))->query($query);
    }

    public function queryForValidation(CollectionSource $source, array $query, BeamUxEntry $entry): array
    {
        $stem = $this->stem($entry, $source);

        $history = $stem === null ? [] : $this->history($stem);
        if ($history === [] || ($this->profiles->fieldSchema($source)['properties']['fields'] ?? null) !== $history[array_key_last($history)]) {
            return $query; // An alternate host target cannot borrow a different registry's drop proof.
        }

        return (new DeclaredFieldRenames($history))->queryForValidation($query);
    }

    public function settings(BeamUxEntry $entry, \Splicewire\Beam\Ux\Data\EntryPageSettingsData $settings, bool $reading = false): \Splicewire\Beam\Ux\Data\EntryPageSettingsData
    {
        $settings = clone $settings;
        if ($reading && $settings->fields !== []) {
            $stem = \Splicewire\Beam\Ux\Containment\PageFields::forParent($entry, $settings->parentId);
            if ($stem !== null && ! class_exists($stem)) {
                $settings->fields = $this->pageFields($stem, $settings->fields, true);
            }
        }
        if ($settings->recordPage !== null) {
            $old = $settings->recordPage->field;
            $settings->recordPage = \Splicewire\Beam\Ux\Data\RecordPageData::from($this->recordPage($entry, $settings->recordPage->toArray()));
            if ($settings->segment === '{'.$old.'}') {
                $settings->segment = '{'.$settings->recordPage->field.'}';
            }
        }

        return $settings;
    }

    public function recordPage(BeamUxEntry $entry, array $declaration): array
    {
        $source = $this->profiles->sources($entry)[$declaration['collection'] ?? ''] ?? null;
        $stem = $source === null ? null : $this->stem($entry, $source);
        if ($stem === null) {
            return $declaration;
        }
        $evolution = new DeclaredFieldRenames($this->history($stem));
        foreach (['field', 'titleField', 'descriptionField', 'socialImageField'] as $key) {
            if (is_string($declaration[$key] ?? null)) {
                $declaration[$key] = $evolution->field($declaration[$key]) ?? $declaration[$key];
            }
        }

        return $declaration;
    }

    public function stem(BeamUxEntry $entry, CollectionSource $source): ?string
    {
        $stem = $source->address['collection'] ?? null;
        if ($source->parentEntryId !== null) {
            $stem = $entry->newQuery()->whereKey($source->parentEntryId)->value('child_fields');
        }

        // Legacy registered Data classes and ordinary resources never enter schema rename projection.
        return is_string($stem) && filter_var($stem, FILTER_VALIDATE_URL) ? $stem : null;
    }

    /** Actual bound stems only, including declared relation targets; never a schema census. */
    public function referencedStems(BeamUxEntry $entry, array $body): array
    {
        $sources = [];
        foreach ($this->profiles->sources($entry) as $name => $source) {
            $sources[$name] = $this->stem($entry, $source);
        }
        $declaration = $entry->getAttribute('record_page');
        $root = is_array($declaration) ? ($sources[$declaration['collection']] ?? null) : null;
        $stems = $root === null ? [] : [$root => true];
        $walk = function (array $nodes, ?string $scope) use (&$walk, &$stems, $sources): void {
            foreach ($nodes as $node) {
                $current = $scope;
                if (isset($node['repeat'])) {
                    $repeat = $node['repeat'];
                    $current = isset($repeat['collection']) ? ($sources[$repeat['collection']] ?? null)
                        : ($scope === null ? null : $this->relatedStem($scope, $repeat['relation'] ?? ''));
                    if ($current !== null) {
                        $stems[$current] = true;
                    }
                    $walk($repeat['empty'] ?? [], $scope);
                }
                $walk(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node), $current);
            }
        };
        $walk($body, $root);
        $names = array_keys($stems);
        sort($names, SORT_STRING);

        return $names;
    }

    private function relatedStem(string $stem, string $relation): ?string
    {
        $history = $this->history($stem);
        $latest = $history[array_key_last($history)] ?? null;

        return $latest === null || ! array_key_exists('x-beam-collection', $latest) ? null
            : ((new CollectionDefinition($latest))->relations()[$relation]['collection'] ?? null);
    }

    private function walk(array $nodes, array $sources, ?string $scope, array &$histories): array
    {
        foreach ($nodes as &$node) {
            $current = $scope;
            if (isset($node['repeat'])) {
                $repeat = &$node['repeat'];
                if (isset($repeat['collection'])) {
                    $current = $sources[$repeat['collection']] ?? null;
                } elseif ($scope !== null) {
                    $current = $this->relatedStem($scope, $repeat['relation'] ?? '');
                }
                if (isset($repeat['empty'])) {
                    $repeat['empty'] = $this->walk($repeat['empty'], $sources, $scope, $histories);
                }
                if ($current !== null && isset($repeat['query']) && is_array($repeat['query'])) {
                    try {
                        $repeat['query'] = (new DeclaredFieldRenames($histories[$current] ??= $this->history($current)))->query($repeat['query']);
                    } catch (DroppedBindingField) {
                        // Keep the declaration visible in the editor; its read is empty and a new
                        // write still goes through the owner's ordinary query validator.
                    }
                }
                unset($repeat);
            }
            if ($current !== null) {
                $renames = new DeclaredFieldRenames($histories[$current] ??= $this->history($current));
                $rename = static fn ($path) => is_string($path) && str_starts_with($path, 'fields.')
                    ? 'fields.'.($renames->field(substr($path, 7)) ?? substr($path, 7)) : $path;
                if (isset($node['field'])) {
                    $node['field'] = $rename($node['field']);
                }
                if (isset($node['props'])) {
                    foreach ($node['props'] as &$prop) {
                        if (($prop['kind'] ?? null) === 'field') {
                            $prop['value'] = $rename($prop['value']);
                        }
                    }
                    unset($prop);
                }
                foreach (['all', 'any'] as $join) {
                    if (isset($node['when'][$join])) {
                        foreach ($node['when'][$join] as &$rule) {
                            $rule['field'] = $rename($rule['field']);
                        }
                        unset($rule);
                    }
                }
            }
            if (isset($node['children'])) {
                $node['children'] = $this->walk($node['children'], $sources, $current, $histories);
            }
            foreach ($node['slots'] ?? [] as $name => $children) {
                $node['slots'][$name] = $this->walk($children, $sources, $current, $histories);
            }
        }
        unset($node);

        return $nodes;
    }
}
