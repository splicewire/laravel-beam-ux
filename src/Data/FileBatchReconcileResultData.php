<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('File batch reconcile result')]
final class FileBatchReconcileResultData extends BeamData
{
    /** @param list<FileReconcileResultData> $entries */
    public function __construct(public int $count, public array $entries) {}
}
