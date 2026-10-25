<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class EntryComponentCapabilitiesOutputData extends BeamData
{
    /** reachEntries counts entries at Working Head or Publication Pin, never pages or instance occurrences. */
    public function __construct(public string $id, public bool $canEdit, public ?int $reachEntries, public int $reachLimit, public bool $reachUnavailable) {}
}
