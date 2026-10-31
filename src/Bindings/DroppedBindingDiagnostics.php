<?php

namespace Splicewire\Beam\Ux\Bindings;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** The validator's actual scopes and frozen declarations, shared by author diagnostics and doctor. */
final class DroppedBindingDiagnostics
{
    public function __construct(private BindingFieldValidator $fields, private BindingFieldEvolution $evolution) {}

    /** @return array<string, list<string>> Known dropped references by structural node location. */
    public function inspect(BeamUxEntry $entry, array $body, array $scopedProfile, ?callable $relation = null): array
    {
        $out = [];
        $this->fields->visit($body, $scopedProfile, $relation, function ($node, $scope, $path) use ($entry, &$out) {
            if ($scope === null) {
                return;
            }
            $references = isset($node['field']) ? [$node['field']] : [];
            foreach ($node['props'] ?? [] as $prop) {
                if (($prop['kind'] ?? null) === 'field') {
                    $references[] = $prop['value'];
                }
            }
            foreach (($node['when']['all'] ?? $node['when']['any'] ?? []) as $rule) {
                $references[] = $rule['field'];
            }
            foreach (array_unique($references) as $field) {
                if (! is_string($field) || ! str_starts_with($field, 'fields.')) {
                    continue; // id/url belong to the Record envelope, not the evolving schema fields.
                }
                try {
                    $this->fields->declaredField($field, $scope);
                } catch (MissingBindingField) {
                    if ($this->evolution->droppedScope($entry, $scope, $field) !== null) {
                        $out[$path][] = $field;
                    }
                }
            }
            if (isset($node['repeat']['query'])) {
                $stem = $scope['schemaStem'] ?? (isset($scope['source']) ? $this->evolution->stem($entry, $scope['source']) : null);
                $evolution = new DeclaredFieldRenames($stem === null ? [] : $this->evolution->history($stem));
                foreach ($evolution->droppedQueryFields($node['repeat']['query']) as $field) {
                    $out[$path][] = $field;
                }
            }
        }, function ($node, $path) use (&$out) {
            $out[$path][] = 'Collection '.($node['repeat']['collection'] ?? '?').' is unavailable';
        });

        return $out;
    }
}
