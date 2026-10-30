<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum FileDraftDiagnosticCode: string implements ProvidesEnumLabel
{
    case CompileFailed = 'compile-failed';

    public function label(): string
    {
        return match ($this) {
            self::CompileFailed => 'Compile failed',
        };
    }
}
