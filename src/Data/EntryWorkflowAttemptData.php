<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Workflows\Data\WorkflowTransitionAttemptData;

#[TypeScript, Title('Entry workflow action')]
final class EntryWorkflowAttemptData extends WorkflowTransitionAttemptData
{
    public ?ReleaseData $release = null;
}
