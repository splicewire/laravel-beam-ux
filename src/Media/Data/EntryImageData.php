<?php

namespace Splicewire\Beam\Ux\Media\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Data\BeamData;

/** An entry image choice; storage placement, morph coordinates and private metadata never travel. */
#[Title('Entry image')]
final class EntryImageData extends BeamData
{
    public function __construct(public string $id, public string $name, public string $url) {}

    public static function fromMedia(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): self
    {
        return new self((string) $media->getKey(), $media->name, $media->getUrl());
    }
}
