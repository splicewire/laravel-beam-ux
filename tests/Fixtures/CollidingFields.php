<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures;

use Splicewire\Beam\Data\BeamData;

/** A misdeclared Page Fields class: `title` is a page setting's key. */
final class CollidingFields extends BeamData
{
    public function __construct(public ?string $title = null) {}
}
