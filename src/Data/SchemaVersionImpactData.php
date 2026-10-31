<?php

namespace Splicewire\Beam\Ux\Data;

use Splicewire\Beam\Data\BeamData;

#[\Spatie\TypeScriptTransformer\Attributes\TypeScript]
final class SchemaVersionImpactData extends BeamData
{
    /** @param list<SchemaVersionPageImpactData> $pages */
    public function __construct(public string $schemaFingerprint, #[\Spatie\LaravelData\Attributes\DataCollectionOf(SchemaVersionPageImpactData::class)] public array $pages) {}

    /** @return array<string, string> */
    public function publishedVersions(): array
    {
        $pins = [];
        foreach ($this->pages as $page) {
            $pins[$page->id] = $page->publishedVersion;
            $pins += $page->dependencies;
        }
        ksort($pins, SORT_STRING);

        return $pins;
    }
}
