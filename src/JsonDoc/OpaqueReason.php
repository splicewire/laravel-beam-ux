<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * ADR-0228 d2/d5 — why a node could not be lowered to a block and is carried verbatim as an Opaque
 * Island (`source` kept as-is). The TS twin of `json.ts`'s `JsonOpaque['reason']`. An island is legal
 * only on a central entry; a tenant-owned entry refuses one (ADR-0227 d1 / ADR-0228 d7, enforced later).
 */
#[TypeScript]
enum OpaqueReason: string
{
    case Map = 'map';
    case Conditional = 'conditional';
    case Ternary = 'ternary';
    case Expression = 'expression';
    case Spread = 'spread';
}
