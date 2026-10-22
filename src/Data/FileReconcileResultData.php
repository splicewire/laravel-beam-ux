<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class FileReconcileResultData extends BeamData
{
    public function __construct(
        public string $status,
        public string $message,
        public string $entryId,
        public ?string $headVersion,
        public ?string $publishedVersion,
    ) {}
}
