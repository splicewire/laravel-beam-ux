<?php

namespace Splicewire\Beam\Ux\Media\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Data\BeamData;

final class EntryImageLibraryData extends BeamData
{
    /** @param list<EntryImageData> $items */
    public function __construct(#[DataCollectionOf(EntryImageData::class)] public array $items) {}
}
