<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Ux\Codec\JsonDocShape;

/** Pure preflight over a publication snapshot, never the mutable working body or Record migration outcome. */
final class SchemaBindingImpact
{
    /** @return list<string> Dropped field references on this published page. */
    public function fields(array $versions, array $schema, array $body, array $sources, ?array $recordPage, ?callable $relation = null): array
    {
        $body = JsonDocShape::is($body) ? $body : [];
        $stem = SchemaId::from($schema['$id'])->stem();
        if (! in_array($stem, $sources, true) && ! $this->hasRelations($body)) {
            return [];
        }
        $evolution = new DeclaredFieldRenames([...$versions, $schema]);
        $root = null;
        if ($recordPage !== null && ($sources[$recordPage['collection'] ?? ''] ?? null) === $stem) {
            $root = $stem;
            $address = $evolution->field($recordPage['field']);
            $property = $address === null ? [] : ($schema['properties'][$address] ?? []);
            if (! array_key_exists('x-beam-collection', $schema)
                || ($property['type'] ?? null) !== 'string' || ($property['x-beam-unique'] ?? false) !== true) {
                throw ValidationException::withMessages(['schema' => 'Rebind the published Record Page before dropping its URL field or removing its unique-string declaration.']);
            }
        }
        $dropped = [];
        $this->walk($body, $sources, $root, $stem, $evolution, $versions, $schema, $dropped, $relation);
        $dropped = array_keys($dropped);
        sort($dropped, SORT_STRING);

        return $dropped;
    }

    /** Only actual binding slots, including the Repeat empty subtree; literal props are not references. */
    public function collections(array $body, ?array $recordPage = null): array
    {
        $body = JsonDocShape::is($body) ? $body : [];
        $names = is_string($recordPage['collection'] ?? null) ? [$recordPage['collection']] : [];
        foreach ($body as $node) {
            if (is_string($node['repeat']['collection'] ?? null)) {
                $names[] = $node['repeat']['collection'];
            }
            $names = [...$names, ...$this->collections($node['repeat']['empty'] ?? []),
                ...$this->collections(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node))];
        }

        return array_values(array_unique($names));
    }

    private function hasRelations(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (isset($node['repeat']['relation'])
                || $this->hasRelations($node['repeat']['empty'] ?? [])
                || $this->hasRelations(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node))) {
                return true;
            }
        }

        return false;
    }

    private function walk(array $nodes, array $sources, ?string $scope, string $stem, DeclaredFieldRenames $evolution, array $versions, array $schema, array &$dropped, ?callable $relation): void
    {
        foreach ($nodes as $node) {
            $current = $scope;
            if (isset($node['repeat'])) {
                $repeat = $node['repeat'];
                // Relations are resolved by the caller's actual source map, never inferred from a name.
                $current = isset($repeat['collection']) ? ($sources[$repeat['collection']] ?? null)
                    : ($scope !== null && $relation !== null ? $relation($scope, $repeat['relation'] ?? '') : null);
                $this->walk($repeat['empty'] ?? [], $sources, $scope, $stem, $evolution, $versions, $schema, $dropped, $relation);
                if ($current === $stem) {
                    $query = $repeat['query'] ?? [];
                    try {
                        $rewritten = $evolution->query($query);
                    } catch (DroppedBindingField) {
                        $rewritten = $query;
                    }
                    foreach (['filter' => 'x-filter', 'sort' => 'x-sort'] as $key => $keyword) {
                        $aliases = $key === 'filter' ? array_keys($rewritten[$key] ?? []) : array_map(fn ($sort) => ltrim($sort, '-'), explode(',', $rewritten[$key] ?? ''));
                        foreach ($versions as $old) {
                            foreach ($old['properties'] ?? [] as $field => $property) {
                                if (in_array($property[$keyword]['name'] ?? null, $aliases, true) && $evolution->field($field) === null) {
                                    $dropped[$field] = true;
                                }
                            }
                        }
                    }
                }
            }
            if ($current === $stem) {
                $paths = isset($node['field']) ? [$node['field']] : [];
                foreach ($node['props'] ?? [] as $prop) {
                    if (($prop['kind'] ?? null) === 'field') {
                        $paths[] = $prop['value'];
                    }
                }
                foreach (['all', 'any'] as $join) {
                    foreach ($node['when'][$join] ?? [] as $rule) {
                        $paths[] = $rule['field'];
                    }
                }
                foreach ($paths as $path) {
                    // Collection records wrap schema fields; the resource's own id/url never rename.
                    if (is_string($path) && str_starts_with($path, 'fields.') && $evolution->field(substr($path, 7)) === null) {
                        $dropped[substr($path, 7)] = true;
                    }
                }
            }
            $this->walk(\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($node), $sources, $current, $stem, $evolution, $versions, $schema, $dropped, $relation);
        }
    }
}
