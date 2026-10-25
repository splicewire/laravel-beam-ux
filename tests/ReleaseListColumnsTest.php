<?php

namespace Splicewire\Beam\Ux\Tests;

use Schemastud\Frame\Registry\ContextManifest;
use Splicewire\Beam\Ux\Data\ReleaseData;

class ReleaseListColumnsTest extends TestCase
{
    public function test_release_manifest_declares_a_compact_list_column_set(): void
    {
        $manifest = (new ContextManifest)->forResource(ReleaseData::class, key: 'beam-ux-release');

        $columns = collect($manifest['byNode'])
            ->filter(fn (array $contexts): bool => ($contexts['list-column']['participates'] ?? false) === true)
            ->map(fn (array $contexts): array => $contexts['list-column'])
            ->all();
        uasort($columns, fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        $this->assertSame(['title', 'status', 'realm'], array_keys($columns));
        $this->assertSame(['Title', 'Status', 'Realm'], array_column($columns, 'label'));
        $this->assertSame([0, 1, 2], array_column($columns, 'sort'));
    }
}
