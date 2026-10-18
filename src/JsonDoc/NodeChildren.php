<?php

namespace Splicewire\Beam\Ux\JsonDoc;

/** Child content in the same scope; Repeat.empty deliberately retains its separate outer scope. */
final class NodeChildren
{
    public static function of(array $node): array
    {
        return array_merge($node['children'] ?? [], ...array_values($node['slots'] ?? []));
    }
}
