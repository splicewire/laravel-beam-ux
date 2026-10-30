<?php

namespace Splicewire\Beam\Ux\Analytics;

use Illuminate\Validation\ValidationException;

/**
 * ADR-0234 decision 13: a save (and a Publish) checks every block's Click Event name against the Event Vocabulary the
 * Manifest Profile serves as `x-events`, as a Repeat's Collection is checked against `x-collections`. It walks children,
 * named slots and a Repeat's empty subtree; it reads no Records and fires nothing.
 */
final class ClickEventGuard
{
    /** @param list<array<string, mixed>> $body */
    public function assert(array $body, array $profile): void
    {
        $declared = array_column($profile['x-events'] ?? [], 'name');
        $this->walk($body, array_flip($declared));
    }

    /** @param array<string, int> $declared */
    private function walk(array $nodes, array $declared): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || ($node['kind'] ?? null) !== 'block') {
                continue;
            }
            $name = $node['clickEvent']['name'] ?? null;
            if ($name !== null && ! isset($declared[$name])) {
                throw ValidationException::withMessages([
                    'clickEvent.name' => "The Click Event [{$name}] is not in this Manifest Profile's Event Vocabulary.",
                ]);
            }
            $this->walk($node['children'] ?? [], $declared);
            foreach ($node['slots'] ?? [] as $slot) {
                $this->walk($slot, $declared);
            }
            $this->walk($node['repeat']['empty'] ?? [], $declared);
        }
    }
}
