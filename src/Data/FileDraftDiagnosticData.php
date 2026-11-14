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
        // Keyed (localization 23); outside a booted application (no translator) the declared English stands.
        return new self(FileDraftDiagnosticCode::CompileFailed, function_exists('app') && app()->bound('translator')
            ? (string) __('beam-ux::files.diagnostic.compile_failed')
            : 'Draft retained; compilation failed. Published content is unchanged. Review the draft before publication.');
    }
}
