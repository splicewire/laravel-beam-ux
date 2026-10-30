<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Safe response-only guidance. No raw compiler diagnostics or durable history claim. */
#[TypeScript, Title('File draft diagnostic')]
final class FileDraftDiagnosticData extends BeamData
{
    public function __construct(
        public FileDraftDiagnosticCode $code,
        public string $message,
    ) {}

    public static function compileFailed(): self
    {
        return new self(FileDraftDiagnosticCode::CompileFailed,
            'Draft retained; compilation failed. Published content is unchanged. Review the draft before publication.');
    }
}
