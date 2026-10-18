<?php

namespace Splicewire\Beam\Ux\Bindings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class RepeatQueryData extends BeamData
{
    public function __construct(
        public ?array $filter = null,
        public ?string $sort = null,
        public int $limit = 25,
        public ?string $filterVariant = null,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('The Repeat limit must be between 1 and 100.');
        }
    }
}
