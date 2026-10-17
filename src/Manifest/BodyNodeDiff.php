<?php

namespace Splicewire\Beam\Ux\Manifest;

/**
 * ADR-0228 d8 — the set of node ids a save ADDS or CHANGES versus the Working Head (the entry's current
 * stored body). "Changed" is decided by CONTENT per id, never by id presence alone (ADR-0227 security
 * condition): a new id, an id whose own content or parent changed, and a DUPLICATED id are all changes.
 *
 * A node's signature carries its parent id and its own content MINUS its children (each child is a node
 * in its own right, diffed separately), so moving a node under a new parent is a change while an
 * untouched subtree is not. The save check (ProfileLegality) narrows its LEGALITY checks to these ids;
 * a node with no id cannot be matched and the check treats it as always-checked.
 */
final class BodyNodeDiff
{
    /**
     * @param  array<int, array<string, mixed>>  $workingHead  the current stored body
     * @param  array<int, array<string, mixed>>  $incoming     the body being saved
     * @return array<string, true>  the ids whose nodes the save adds or changes
     */
    public function changedIds(array $workingHead, array $incoming): array
    {
        $before = [];
        $this->collect($workingHead, null, $before, $beforeCounts);

        $after = [];
        $afterCounts = [];
        $this->collect($incoming, null, $after, $afterCounts);

        $changed = [];
        foreach ($after as $id => $signature) {
            if (($afterCounts[$id] ?? 0) > 1) {            // a duplicated id is never "unchanged"
                $changed[$id] = true;
            } elseif (! array_key_exists($id, $before)) {  // added
                $changed[$id] = true;
            } elseif ($before[$id] !== $signature) {       // own content or parent changed
                $changed[$id] = true;
            }
        }

        return $changed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, string>  $signatures  id => content signature (by reference)
     * @param  array<string, int>|null  $counts     id => occurrence count (by reference)
     */
    private function collect(array $nodes, ?string $parentId, array &$signatures, ?array &$counts = null): void
    {
        $counts ??= [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $node['id'] ?? null;
            if (is_string($id)) {
                // The node's own identity: its parent plus everything but its children.
                $own = $node;
                unset($own['children']);
                $signatures[$id] = json_encode([$parentId, $own], JSON_THROW_ON_ERROR);
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }

            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $this->collect($children, is_string($id) ? $id : $parentId, $signatures, $counts);
            }
        }
    }
}
