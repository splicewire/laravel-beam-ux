<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Versioning\Contracts\VersionStore;
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
                $records[] = (new ChildPageRecordData((string) $entry->id, $entry->slug, $entry->title, $this->urls->resolveChain($chain), $this->fields($entry)))->toArray();
            }
        }

        return $records;
    }

    /**
     * A child's Page Fields as its published version holds them (ADR-0233 d4).
     *
     * @return array<string, string|int|float|bool>
     */
    private function fields(BeamUxEntry $entry): array
    {
        $pin = $entry->getAttribute('published_version');
        if ($entry->particle_id === null || $pin === null || ! app()->bound(VersionStore::class)) {
            return [];
        }

        return app(VersionStore::class)->version($entry->versionable(), (string) $pin)?->snapshot['meta']['pageSettings']['fields'] ?? [];
    }
}
