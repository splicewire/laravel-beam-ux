<?php

namespace Splicewire\Beam\Ux\Bindings;

use RuntimeException;
use Splicewire\Beam\Ux\Data\SchemaVersionImpactData;

final class SchemaVersionImpactRequired extends RuntimeException
{
    public function __construct(public readonly SchemaVersionImpactData $impact)
    {
        parent::__construct('Review the published pages affected by these dropped fields before freezing this version.');
    }
}
