<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * ADR-0228 d2 — the five prop kinds a block's attribute may carry, the TS twin of
 * `@splicewire/beam-ux/blockdoc/json.ts`'s `PropKind` and the PHP `JsonDocPrinter::printProp()` switch
 * (boolean-shorthand / string / number / boolean / expression). Generated to TypeScript, so the hand
 * union retires. (ADR-0229 d3 + ADR-0231 d8 add `field` and `prop` in later slices; EP-05 is the five.)
 */
#[TypeScript]
enum PropKind: string
{
    case BooleanShorthand = 'boolean-shorthand';
    case StringValue = 'string';
    case NumberValue = 'number';
    case BooleanValue = 'boolean';
    case Expression = 'expression';
    case Field = 'field';
}
