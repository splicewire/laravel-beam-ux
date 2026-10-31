<?php

namespace Splicewire\Beam\Ux\Sync;

use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Sync\Transit\SyncData;

/** Published entry transit. `id` is SOURCE identity only; the target always mints its own. */
final class EntryPackSyncData extends SyncData
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>  $schemaSources  profile alias => registered schema stem
     */
    public function __construct(
        public string $id,
        public EntryPackCoordinateData $coordinate,
        public array $attributes,
        #[\Spatie\LaravelData\Attributes\MapName('parent_id')]
        public ?string $parent_id,
        public array $body,
        public ?EntryPageSettingsData $pageSettings = null,
        #[\Schemastud\DataSchemas\Attributes\MapValues('string')]
        public array $schemaSources = [],
    ) {}
}
