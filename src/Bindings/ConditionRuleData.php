<?php

namespace Splicewire\Beam\Ux\Bindings;

use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class ConditionRuleData extends BeamData
{
    public function __construct(
        public string $field,
        public ConditionOperator $operator,
        #[Optional]
        public string|int|float|bool|null $value = null,
    ) {
        FieldPath::assert($field);
    }
}
