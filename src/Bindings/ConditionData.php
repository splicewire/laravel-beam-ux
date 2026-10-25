<?php

namespace Splicewire\Beam\Ux\Bindings;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class ConditionData extends BeamData
{
    /** @param list<ConditionRuleData>|null $all
     * @param  list<ConditionRuleData>|null  $any
     */
    public function __construct(
        #[DataCollectionOf(ConditionRuleData::class)]
        #[Optional]
        public ?array $all = null,
        #[DataCollectionOf(ConditionRuleData::class)]
        #[Optional]
        public ?array $any = null,
    ) {
        if (($all === null) === ($any === null) || ($all ?? $any) === []) {
            throw new \InvalidArgumentException('A Data Condition requires one non-empty all or any rule list.');
        }
    }
}
