<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Support\Str;
use Schemastud\DataSchemas\Attributes\ArrayItems;
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
        /** @var list<string>|null */
        #[Optional]
        #[ArrayItems('string')]
        public ?array $ids = null,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('The Repeat limit must be between 1 and 100.');
        }
        if ($ids !== null && (! array_is_list($ids) || count($ids) > 100
            || array_filter($ids, static fn ($id): bool => ! is_string($id) || ! Str::isUuid($id)) !== [])) {
            throw new \InvalidArgumentException('Repeat ids must be a list of at most 100 record UUIDs.');
        }
    }
}
