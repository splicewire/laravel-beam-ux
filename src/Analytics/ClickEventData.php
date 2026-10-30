<?php

namespace Splicewire\Beam\Ux\Analytics;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * ADR-0234 decision 13: a block's Click Event — a name from the Event Vocabulary the Manifest Profile serves
 * (ADR-0237 decision 8) and an optional label, chosen in the inspector. A save checks the name
 * ({@see ClickEventGuard}); the source printer writes it as `bdEvent`, and the compiled artifact as `data-bd-event`,
 * which the site runtime reports on a visitor's click. An absent label is omitted, never `null`.
 */
#[TypeScript, Title('Click Event')]
final class ClickEventData extends BeamData
{
    public function __construct(
        public string $name,
        public string|Optional $label = new Optional,
    ) {}
}
