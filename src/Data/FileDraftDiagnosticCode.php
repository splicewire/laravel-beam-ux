<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FileDraftDiagnosticCode: string
{
    case CompileFailed = 'compile-failed';
}
