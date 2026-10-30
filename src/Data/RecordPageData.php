<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/** ADR-0229 d8: names offered by this entry's profile, never resource classes or client addresses. */
#[TypeScript, Title('Record page')]
final class RecordPageData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        public string $collection,
        public string $field,
        public ?string $titleField = null,
        public ?string $descriptionField = null,
        public ?string $socialImageField = null,
    ) {}
}
