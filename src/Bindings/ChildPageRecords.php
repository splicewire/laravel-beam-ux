<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Data\ChildPageRecordData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/** One adjacency read; the same publish/access gates and URL composer used by navigation. */
final class ChildPageRecords
{
    public function __construct(private EntryAccessResolver $access, private EntryPublishGate $publish, private UrlResolver $urls) {}

    /** @return list<array<string, mixed>> */
    public function read(string $parentId, string $realm, ?Authenticatable $visitor): array
    {
        $entries = BeamUxEntry::query()->whereJsonContains('realms', $realm)
            ->where('type', UxType::Page->value)->orderBy('slug')->get()->keyBy('id');
        $records = [];
        foreach ($entries->where('parent_id', $parentId) as $entry) {
            if ($entry->segment === null || $entry->segment === '') {
                continue;
            }
            $chain = [];
            $seen = [];
            $node = $entry;
            while ($node !== null) {
                if (isset($seen[$node->id]) || ! $this->publish->isPublished($node)) {
                    continue 2;
                }
                $seen[$node->id] = true;
                array_unshift($chain, $node);
                if ($node->parent_id !== null && ! $entries->has($node->parent_id)) {
                    continue 2;
                }
                $node = $node->parent_id === null ? null : $entries->get($node->parent_id);
            }
            if ($this->access->canList($visitor, $chain)) {
                $records[] = (new ChildPageRecordData((string) $entry->id, $entry->slug, $entry->title, $this->urls->resolveChain($chain)))->toArray();
            }
        }

        return $records;
    }
}
