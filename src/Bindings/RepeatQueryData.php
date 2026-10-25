<?php

namespace Splicewire\Beam\Ux\Bindings;

use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class RepeatQueryData extends BeamData
{
    /** @param array<string, mixed>|null $filter */
    public function __construct(
        #[Optional]
        public ?array $filter = null,
        #[Optional]
        public ?string $sort = null,
        #[Optional]
        public int $limit = 25,
        #[Optional]
        public ?string $filterVariant = null,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('The Repeat limit must be between 1 and 100.');
        }
    }
}
