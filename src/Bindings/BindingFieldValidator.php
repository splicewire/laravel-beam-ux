<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Validation\ValidationException;

/** ADR-0229 d3/d11/d13. The offer's owner-generated schema is the only field vocabulary. */
final class BindingFieldValidator
{
    public function assert(array $body, array $profile, ?callable $relation = null, ?callable $missing = null): void
    {
        $entries = array_column($profile['nodes'] ?? [], null, 'name');
        $this->visit($body, $profile, $relation, function ($node, $scope, $path, $context) use ($entries, $missing) {
            $resolve = $missing === null ? null : fn ($field, $scope) => $missing($field, $scope, $node, $path, $context);
            $this->assertNode($node, $scope, $entries, $resolve);
        });
    }

    /** Declaration-only scope traversal shared by validation and read-only dropped-binding diagnosis. */
    public function visit(array $body, array $profile, ?callable $relation, callable $visit, ?callable $missingSource = null): void
    {
        $this->walk($body, array_column($profile['x-collections'] ?? [], null, 'name'), $profile['x-record'] ?? null, $relation, $visit, $missingSource);
    }

    private function walk(array $nodes, array $offers, ?array $scope, ?callable $relation, callable $visit, ?callable $missingSource, string $path = '', array $context = []): void
    {
        foreach ($nodes as $index => $node) {
            $at = $path.'/'.$index;
            $inner = $context;
            $current = $scope;
            if (isset($node['repeat'])) {
                $repeat = $node['repeat'];
                if (isset($repeat['collection']) && ($scope === null || ($scope['recordPageRoot'] ?? false))) {
                    $current = $offers[$repeat['collection']] ?? null;
                } elseif (isset($repeat['relation']) && ! isset($repeat['collection']) && $scope !== null && $relation !== null) {
                    $current = $relation($scope, $repeat['relation']);
                } else {
                    $this->refuse('A Repeat must name an offered Collection or a declared relation in its outer Record.');
                }
                if ($current === null && $missingSource !== null) {
                    $missingSource($node, $at);
                    $this->walk($repeat['empty'] ?? [], $offers, $scope, $relation, $visit, $missingSource, $at.'/repeat/empty', $context);

                    continue; // No invented Record scope for the missing source's template.
                }
                if ($current === null && isset($repeat['collection'])) {
                    throw MissingProfileCollection::named($repeat['collection']);
                }
                if ($current === null) {
                    $this->refuse('The Repeat source is not offered by this Manifest Profile.');
                }
                // The empty subtree replaces the Repeat, so it has the enclosing scope, not a nonexistent Record.
                $this->walk($repeat['empty'] ?? [], $offers, $scope, $relation, $visit, $missingSource, $at.'/repeat/empty', $context);
                unset($repeat['empty']);
                $inner[] = ['repeat' => $repeat];
            }
            $visit($node, $current, $at, $context);
            if (isset($node['when'])) {
                $inner[] = ['when' => $node['when']];
            }
            $this->walk($node['children'] ?? [], $offers, $current, $relation, $visit, $missingSource, $at.'/children', $inner);
            foreach ($node['slots'] ?? [] as $name => $children) {
                $this->walk($children, $offers, $current, $relation, $visit, $missingSource, $at.'/slots/'.rawurlencode($name), $inner);
            }
        }
    }

    private function assertNode(array $node, ?array $scope, array $entries, ?callable $missing): void
    {
        if (isset($node['field'])) {
            $field = $this->field($node['field'], $scope, $missing);
            $types = (array) ($field['type'] ?? []);
            if ($types === [] || array_diff($types, ['string', 'integer', 'number', 'boolean', 'null'])
                || in_array($field['format'] ?? null, ['markdown', 'mdx', 'blockdoc', 'jsondoc'], true)) {
                $this->refuse('A text binding takes plain fields only; rich fields require a declared rich-field component.');
            }
        }
        foreach ($node['props'] ?? [] as $prop) {
            if (($prop['kind'] ?? null) === 'field') {
                $field = $this->field($prop['value'] ?? null, $scope, $missing);
                $name = $prop['name'] ?? '';
                if (in_array(strtolower($name), ['dangerouslysetinnerhtml', 'srcdoc'], true) || preg_match('/^on/i', $name)) {
                    $this->refuse('A binding cannot target an executable attribute or a raw HTML sink.');
                }
                $format = $field['format'] ?? null;
                if (in_array($format, ['markdown', 'blockdoc', 'jsondoc', 'mdx'], true)) {
                    $renderer = ['markdown' => 'RichMarkdown', 'blockdoc' => 'RichBlockdoc'][$format] ?? null;
                    if ($renderer === null || ($node['name'] ?? null) !== $renderer || $name !== 'content') {
                        $this->refuse('A rich field requires its matching safe content component; MDX is never a rich field.');
                    }
                }
                $attrs = $entries[$node['name'] ?? '']['attrsSchema'] ?? null;
                if (is_array($attrs)) {
                    $target = $attrs['properties'][$prop['name'] ?? ''] ?? ($attrs['additionalProperties'] ?? true);
                    if ($target === false || (is_array($target) && ! \Splicewire\Beam\Ux\Manifest\SchemaCompatibility::fits($field, $target))) {
                        $this->refuse('The binding field does not fit the declared attribute ['.($prop['name'] ?? '?').'].');
                    }
                }
            }
        }
        foreach (($node['when']['all'] ?? $node['when']['any'] ?? []) as $rule) {
            $this->field($rule['field'] ?? null, $scope, $missing);
        }
    }

    /** Resolve a declaration without validating a synthetic document or executing a Record query. */
    public function declaredField(string $path, array $scope): array
    {
        return $this->field($path, $scope, null);
    }

    private function field(mixed $path, ?array $scope, ?callable $missing): array
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
                $historic = $missing === null ? null : $missing($path, $scope);
                if (is_array($historic)) {
                    return $this->field($path, $historic, null);
                }
                throw MissingBindingField::named($path);
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
