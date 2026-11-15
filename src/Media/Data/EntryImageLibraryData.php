<?php

namespace Splicewire\Beam\Ux\Media\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[Title('Image library')]
#[TypeScript]
final class EntryImageLibraryData extends BeamData
{
    /** @param list<EntryImageData> $items */
    public function __construct(#[DataCollectionOf(EntryImageData::class)] public array $items) {}
}
