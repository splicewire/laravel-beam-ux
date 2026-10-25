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
        $problem = $this->problems($context, $changes)[0] ?? null;
        if ($problem !== null) {
            $this->refuse($problem['message']);
        }
    }

    /**
     * Every reason the final graph is refused, the first being the one assertAllowed() throws. A Release's readiness
     * report (ADR-0232 d11) lists them all, reading without locks.
     *
     * @param  array<string, array{parentId: ?string, segment: ?string}>  $changes
     * @return list<array{entryId: string, message: string}>
     */
    public function problems(BeamUxEntry $context, array $changes, bool $lock = true): array
    {
        // Same connection as publication, including tenant-resident entries. Locks survive the caller's transaction.
        $query = $context->newQuery()->orderBy('id');
        $entries = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $problems = [];
        foreach ($changes as $id => $change) {
            if (! $entries->has($id)) {
                $problems[] = ['entryId' => (string) $id, 'message' => 'A changed entry does not exist.'];

                continue;
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
                        $problems[] = ['entryId' => (string) $entry->id, 'message' => 'The proposed page parents contain a cycle.'];

                        continue 2;
                    }
                    break;
                }
                $seen[$node->id] = true;
                array_unshift($chain, $node);
                if ($node->parent_id === null) {
                    break;
                }
                $parent = $entries->get($node->parent_id);
                if ($parent === null || ($parent->published_version === null && ! array_key_exists($parent->id, $changes)
                    && ! $this->isCanonicalStructuralRoot($parent, $entry))) {
                    if (isset($affected[$entry->id])) {
                        $problems[] = ['entryId' => (string) $entry->id, 'message' => 'A page cannot publish under a missing or never-published parent.'];

                        continue 2;
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
                    // Named after the page that moved, which is the one to fix.
                    $problems[] = ['entryId' => (string) (isset($affected[$entry->id]) ? $entry->id : $urls[$url]), 'message' => 'The proposed pages would share a URL.'];

                    continue;
                }
                $urls[$url] = $entry->id;
            }
        }

        return $problems;
    }

    /** ADR-0209 amendment E: the existing realm root is infrastructure, not an authored version. */
    private function isCanonicalStructuralRoot(BeamUxEntry $root, BeamUxEntry $entry): bool
    {
        $realm = $root->getAttribute('realm');
        if ($root->getAttribute('namespace') !== 'realms' || $root->getAttribute('slug') !== $realm
            || $root->getAttribute('type') !== \Splicewire\Beam\Ux\Type\UxType::Page
            || $root->getAttribute('parent_id') !== null || ($root->getAttribute('segment') !== null && $root->getAttribute('segment') !== '')
            || $root->getAttribute('particle_id') !== null || $root->getAttribute('deleted_at') !== null
            || ! in_array($realm, (array) ($root->getAttribute('realms') ?? [$realm]), true)
            || ! in_array($realm, (array) ($entry->getAttribute('realms') ?? [$entry->getAttribute('realm')]), true)) {
            return false;
        }
        // rootFor stamps this when the optional workflow column exists; do not admit a hidden root.
        if (array_key_exists('workflow_marking', $root->getAttributes())
            && $root->getAttribute('workflow_marking') !== BeamUxEntry::MARKING_PUBLISHED) {
            return false;
        }

        return app(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class)->isPublished($root);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['pageSettings.parentId' => $message]);
    }
}
