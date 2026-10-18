<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
#[Title('Repeat records')]
final class RepeatRecordsData extends BeamData
{
    /** @param list<array<string, mixed>> $records Resource-owned projected fields. */
    public function __construct(public array $records, public bool $hasMore, public int $page) {}
}
