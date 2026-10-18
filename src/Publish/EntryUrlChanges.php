<?php

namespace Splicewire\Beam\Ux\Publish;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** ADR-0232 d2: validate the final graph, not each intermediate move. EP-15 supplies a batch. */
final class EntryUrlChanges
{
    /** @param array<string, array{parentId: ?string, segment: ?string}> $changes */
    public function assertAllowed(BeamUxEntry $context, array $changes): void
    {
        // Same connection as publication, including tenant-resident entries. Locks survive the caller's transaction.
        $entries = $context->newQuery()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($changes as $id => $change) {
            if (! $entries->has($id)) {
                $this->refuse('A changed entry does not exist.');
            }
            $entries[$id]->parent_id = $change['parentId'];
            $entries[$id]->segment = $change['segment'];
        }
        $affected = array_fill_keys(array_keys($changes), true);
        do {
            $count = count($affected);
            foreach ($entries as $entry) {
                if ($entry->parent_id !== null && isset($affected[$entry->parent_id])) {
                    $affected[$entry->id] = true;
                }
            }
        } while (count($affected) !== $count);
        $urls = [];
        foreach ($entries as $entry) {
            if ($entry->published_version === null && ! array_key_exists($entry->id, $changes)) {
                continue;
            }
            $chain = [];
            $seen = [];
            $node = $entry;
            while ($node !== null) {
                if (isset($seen[$node->id])) {
                    if (isset($affected[$entry->id])) {
                        $this->refuse('The proposed page parents contain a cycle.');
                    }
                    break;
                }
                $seen[$node->id] = true;
                array_unshift($chain, $node);
                if ($node->parent_id === null) {
                    break;
                }
                $parent = $entries->get($node->parent_id);
                if ($parent === null || ($parent->published_version === null && ! array_key_exists($parent->id, $changes))) {
                    if (isset($affected[$entry->id])) {
                        $this->refuse('A page cannot publish under a missing or never-published parent.');
                    }
                    break;
                }
                $node = $parent;
            }
            // A pass-through node has no destination URL of its own.
            if ($entry->type !== \Splicewire\Beam\Ux\Type\UxType::Page || $entry->segment === null || $entry->segment === '') {
                continue;
            }
            $path = app(\Splicewire\Beam\Ux\Containment\UrlResolver::class)->resolveChain($chain);
            $realms = is_array($entry->realms) ? $entry->realms : [$entry->realm];
            foreach ($realms as $realm) {
                $url = $realm.':'.$path;
                if (isset($urls[$url]) && (isset($affected[$entry->id]) || isset($affected[$urls[$url]]))) {
                    $this->refuse('The proposed pages would share a URL.');
                }
                $urls[$url] = $entry->id;
            }
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['pageSettings.parentId' => $message]);
    }
}
