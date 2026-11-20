<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One Redirect that leads to a page, as its settings list them (ADR-0236 d14).
 *
 * gap-assessment-build 21 / closed-vocabulary convention: an object with named members is a Data class, not a shape
 * hand-written in a `#[LiteralTypeScriptType]` attribute. The members, their names and their nullability are the same
 * the attribute spelled, so the serialized row and the generated type are unchanged in substance.
 */
#[TypeScript, Title('Redirect row')]
final class RedirectRowData extends BeamData
{
    public function __construct(
        public string $path,
        public string $match,
        public bool $permanent,
        public string $origin,
    ) {}
}
