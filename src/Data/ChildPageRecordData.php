<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Data\BeamData;

/** The owning entry package declares the child-page binding vocabulary. */
#[Title('Child page')]
final class ChildPageRecordData extends BeamData
{
    public function __construct(public string $id, public string $slug, public ?string $title, public string $url) {}
}
