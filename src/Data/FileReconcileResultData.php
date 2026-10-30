<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('File reconcile result')]
final class FileReconcileResultData extends BeamData
{
    public function __construct(
        public string $status,
        public string $message,
        public string $entryId,
        public ?string $headVersion,
        public ?string $publishedVersion,
        public ?FileDraftDiagnosticData $diagnostic = null,
    ) {}
}
