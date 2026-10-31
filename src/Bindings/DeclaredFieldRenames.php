<?php

namespace Splicewire\Beam\Ux\Bindings;

use InvalidArgumentException;
use Splicewire\Beam\Schema\SchemaId;

/** A projection of frozen artifacts, using the migration ladder's top-level x-migrate-from declaration. */
final class DeclaredFieldRenames
{
    private array $versions;

    /** @param list<array<string, mixed>> $versions Frozen versions of ONE stem, including the proposed version for preflight. */
    public function __construct(array $versions)
    {
        $ordered = [];
        $stem = null;
        foreach ($versions as $schema) {
            $id = SchemaId::from($schema['$id'] ?? '');
            $stem ??= $id->stem();
            if ($id->version() === null || $id->stem() !== $stem || isset($ordered[$id->version()])) {
                throw new InvalidArgumentException('Field evolution needs distinct frozen versions of one schema stem.');
            }
            $ordered[$id->version()] = $schema;
        }
        ksort($ordered, SORT_NUMERIC);
        $this->versions = array_values($ordered);
    }

    /** Null is a drop/unknown, never a guessed replacement. Nested suffixes survive a declared root rename. */
    public function field(string $path): ?string
    {
        FieldPath::assert($path);
        $parts = explode('.', $path);
        $name = array_shift($parts);
        $current = $this->property($name);

        return $current === null ? null : implode('.', [$current, ...$parts]);
    }

    /** Exact top-level JSON property keys (Page Fields can legally contain punctuation). */
    public function property(string $name): ?string
    {
        $latest = $this->versions[array_key_last($this->versions)] ?? [];
        if (isset($latest['properties'][$name])) {
            return $name;
        }
        $seen = false;
        foreach ($this->versions as $schema) {
            $properties = $schema['properties'] ?? [];
            if (isset($properties[$name])) {
                $seen = true;

                continue;
            }
            if (! $seen) {
                continue;
            }
            $targets = array_keys(array_filter($properties, fn ($property) => is_array($property)
                && ($property['x-migrate-from'] ?? null) === $name));
            if (count($targets) > 1) {
                throw new InvalidArgumentException('A declared field rename must have one destination.');
            }
            if ($targets === []) {
                return null;
            }
            $name = $targets[0];
        }

        return $seen ? $name : null;
    }

    /** Saved filter/sort keys are declared aliases; values and unrelated keys are never rewritten. */
    public function query(array $query): array
    {
        if (is_array($query['filter'] ?? null)) {
            $filter = [];
            foreach ($query['filter'] as $alias => $value) {
                $target = $this->alias((string) $alias, 'x-filter');
                if (array_key_exists($target, $filter)) {
                    throw new InvalidArgumentException('Renamed filters overlap; save one current filter value.');
                }
                $filter[$target] = $value;
            }
            $query['filter'] = $filter;
        }
        if (is_string($query['sort'] ?? null)) {
            $query['sort'] = implode(',', array_map(function (string $sort): string {
                $descending = str_starts_with($sort, '-');

                return ($descending ? '-' : '').$this->alias($descending ? substr($sort, 1) : $sort, 'x-sort');
            }, explode(',', $query['sort'])));
        }

        return $query;
    }

    /** @return list<string> Only known dropped aliases, never unknown names or current renames. */
    public function droppedQueryFields(array $query): array
    {
        if (($query['filterVariant'] ?? null) !== null) {
            return []; // A separate owner vocabulary is not proved by this Collection's artifacts.
        }
        $out = [];
        foreach (['filter' => 'x-filter', 'sort' => 'x-sort'] as $key => $keyword) {
            $aliases = $key === 'filter' ? array_keys($query[$key] ?? [])
                : array_map(fn ($sort) => str_starts_with($sort, '-') ? substr($sort, 1) : $sort, explode(',', $query[$key] ?? ''));
            foreach ($aliases as $alias) {
                try {
                    $this->alias((string) $alias, $keyword);
                } catch (DroppedBindingField) {
                    $out[] = $key.'.'.$alias;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Admission-only projection of an unchanged saved query. The persisted query remains untouched. */
    public function queryForValidation(array $query): array
    {
        if (($query['filterVariant'] ?? null) !== null) {
            return $query; // Keep the complete selected vocabulary for the normal owner validator.
        }
        if (is_array($query['filter'] ?? null)) {
            foreach ($query['filter'] as $alias => $value) {
                try {
                    $this->alias((string) $alias, 'x-filter');
                } catch (DroppedBindingField) {
                    unset($query['filter'][$alias]);
                }
            }
        }
        if (is_string($query['sort'] ?? null)) {
            $kept = [];
            foreach (explode(',', $query['sort']) as $sort) {
                try {
                    $this->alias(str_starts_with($sort, '-') ? substr($sort, 1) : $sort, 'x-sort');
                    $kept[] = $sort;
                } catch (DroppedBindingField) {
                    // Only aliases proved dropped by frozen artifacts are omitted from CURRENT validation.
                }
            }
            $query['sort'] = implode(',', $kept);
        }

        return $this->query($query);
    }

    private function alias(string $alias, string $keyword): string
    {
        $latest = $this->versions[array_key_last($this->versions)] ?? [];
        foreach ($latest['properties'] ?? [] as $property) {
            if (($property[$keyword]['name'] ?? null) === $alias) {
                return $alias;
            }
        }
        $targets = [];
        $declared = false;
        foreach ($this->versions as $schema) {
            foreach ($schema['properties'] ?? [] as $field => $property) {
                if (($property[$keyword]['name'] ?? null) !== $alias) {
                    continue;
                }
                $declared = true;
                $current = $this->field($field);
                $target = $current === null ? null : ($latest['properties'][$current][$keyword]['name'] ?? null);
                if (is_string($target)) {
                    $targets[$target] = true;
                }
            }
        }
        if (count($targets) > 1) {
            throw new InvalidArgumentException('A saved query alias has ambiguous declared successors.');
        }

        if ($declared && $targets === []) {
            throw new DroppedBindingField('The saved query field ['.$alias.'] was dropped or no longer declares this query alias.');
        }

        return array_key_first($targets) ?? $alias;
    }
}
