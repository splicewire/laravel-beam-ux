<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Validation\ValidationException;

/** ADR-0229 d3/d11/d13. The offer's owner-generated schema is the only field vocabulary. */
final class BindingFieldValidator
{
    public function assert(array $body, array $profile, ?callable $relation = null): void
    {
        $offers = array_column($profile['x-collections'] ?? [], null, 'name');
        $this->walk($body, $offers, null, $relation, array_column($profile['nodes'] ?? [], null, 'name'));
    }

    private function walk(array $nodes, array $offers, ?array $scope, ?callable $relation, array $entries): void
    {
        foreach ($nodes as $node) {
            $current = $scope;
            if (isset($node['repeat'])) {
                $repeat = $node['repeat'];
                if (isset($repeat['collection']) && $scope === null) {
                    $current = $offers[$repeat['collection']] ?? null;
                } elseif (isset($repeat['relation']) && ! isset($repeat['collection']) && $scope !== null && $relation !== null) {
                    $current = $relation($scope, $repeat['relation']);
                } else {
                    $this->refuse('A Repeat must name an offered Collection or a declared relation in its outer Record.');
                }
                if ($current === null) {
                    $this->refuse('The Repeat source is not offered by this Manifest Profile.');
                }
                // The empty subtree replaces the Repeat, so it has the enclosing scope, not a nonexistent Record.
                $this->walk($repeat['empty'] ?? [], $offers, $scope, $relation, $entries);
            }
            if (isset($node['field'])) {
                $field = $this->field($node['field'], $current);
                $types = (array) ($field['type'] ?? []);
                if ($types === [] || array_diff($types, ['string', 'integer', 'number', 'boolean', 'null'])
                    || in_array($field['format'] ?? null, ['markdown', 'mdx', 'blockdoc', 'jsondoc'], true)) {
                    $this->refuse('A text binding takes plain fields only; rich fields require a declared rich-field component.');
                }
            }
            foreach ($node['props'] ?? [] as $prop) {
                if (($prop['kind'] ?? null) === 'field') {
                    $field = $this->field($prop['value'] ?? null, $current);
                    $attrs = $entries[$node['name'] ?? '']['attrsSchema'] ?? null;
                    if (is_array($attrs)) {
                        $target = $attrs['properties'][$prop['name'] ?? ''] ?? ($attrs['additionalProperties'] ?? true);
                        if ($target === false || (is_array($target) && ! $this->fits($field, $target))) {
                            $this->refuse('The binding field does not fit the declared attribute ['.($prop['name'] ?? '?').'].');
                        }
                    }
                }
            }
            foreach (($node['when']['all'] ?? $node['when']['any'] ?? []) as $rule) {
                $this->field($rule['field'] ?? null, $current);
            }
            $this->walk($node['children'] ?? [], $offers, $current, $relation, $entries);
        }
    }

    /** Conservative schema containment: never infer compatibility from one sample Record. */
    private function fits(array $source, array $target): bool
    {
        if (isset($source['enum']) || array_key_exists('const', $source)) {
            $values = $source['enum'] ?? [$source['const']];
            $validator = new \Opis\JsonSchema\Validator;
            $schema = json_decode(json_encode((object) $target, JSON_THROW_ON_ERROR));
            foreach ($values as $value) {
                if (! $validator->validate(json_decode(json_encode($value, JSON_THROW_ON_ERROR)), $schema)->isValid()) {
                    return false;
                }
            }

            return true;
        }
        if (isset($target['type'])) {
            $types = (array) ($source['type'] ?? []);
            $allowed = (array) $target['type'];
            if ($types === []) {
                return false;
            }
            foreach ($types as $type) {
                if (! in_array($type, $allowed, true) && ! ($type === 'integer' && in_array('number', $allowed, true))) {
                    return false;
                }
            }
        }
        // An arbitrary source string is not a member of a target enum. Other constraints must
        // be carried by the source verbatim until a dedicated containment rule supports them.
        foreach ($target as $key => $value) {
            if (in_array($key, ['type', 'title', 'description', '$comment', 'default', 'examples', '$schema', '$id'], true)) {
                continue;
            }
            if (! array_key_exists($key, $source) || $source[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function field(mixed $path, ?array $scope): array
    {
        try {
            FieldPath::assert($path);
        } catch (\InvalidArgumentException $e) {
            $this->refuse($e->getMessage());
        }
        if ($scope === null) {
            $this->refuse('A Field Binding needs a Record in scope.');
        }
        if ($path === 'url') {
            if (($scope['hasRecordPage'] ?? false) !== true) {
                $this->refuse('This Collection has no Record Page; a binding to url is unavailable (ADR-0229 d11).');
            }

            return ['type' => 'string', 'format' => 'uri'];
        }
        $root = $scope['schema'];
        $schema = $root;
        foreach (explode('.', $path) as $part) {
            $schema = $this->dereference($schema, $root);
            if (! isset($schema['properties'][$part]) || ! is_array($schema['properties'][$part])) {
                $this->refuse('The field ['.$path.'] is not declared by the offered Collection.');
            }
            $schema = $schema['properties'][$part];
        }

        return $this->dereference($schema, $root);
    }

    private function dereference(array $schema, array $root): array
    {
        $seen = [];
        while (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            if (! is_string($ref) || ! str_starts_with($ref, '#/') || isset($seen[$ref])) {
                $this->refuse('A binding field schema must have a resolvable local reference.');
            }
            $seen[$ref] = true;
            $target = $root;
            foreach (explode('/', substr($ref, 2)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                $target = is_array($target) ? ($target[$part] ?? null) : null;
            }
            if (! is_array($target)) {
                $this->refuse('A binding field schema reference is missing.');
            }
            unset($schema['$ref']);
            $schema = [...$target, ...$schema];
        }

        return $schema;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['body' => $message]);
    }
}
