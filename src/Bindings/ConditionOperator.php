<?php

namespace Splicewire\Beam\Ux\Bindings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Exact vocabulary of ADR-0229 decision 12. */
#[TypeScript]
enum ConditionOperator: string
{
    case IsSet = 'is set';
    case IsEmpty = 'is empty';
    case Equals = 'equals';
    case DoesNotEqual = 'does not equal';
    case GreaterThan = 'greater than';
    case LessThan = 'less than';
    case Contains = 'contains';
}
