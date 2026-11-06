<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** The declared `input:` of `beam-ux-redirect`'s `traces` read: optionally one realm's Redirects; every realm otherwise. */
#[TypeScript, Title('Redirect traces query')]
final class RedirectTracesInputData extends BeamData
{
    public function __construct(
        public ?string $realm = null,
    ) {}
}
