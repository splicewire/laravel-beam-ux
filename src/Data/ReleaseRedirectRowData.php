<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One Redirect a Release leaves (ADR-0236 d2): while the Release is open, the ones it would leave (no `id` yet); once
 * published, the ones it left, each with its `id` so an unwanted one can be removed through the Redirect resource.
 *
 * gap-assessment-build 21 / closed-vocabulary convention: an object with named members is a Data class, not a shape
 * hand-written in a `#[LiteralTypeScriptType]` attribute. The members, their names and their nullability are the same
 * the attribute spelled, so the serialized row and the generated type are unchanged in substance.
 */
#[TypeScript, Title('Release redirect row')]
final class ReleaseRedirectRowData extends BeamData
{
    public function __construct(
        public ?string $id,
        public string $path,
        public string $match,
        public ?string $to,
        public string $origin,
    ) {}
}
