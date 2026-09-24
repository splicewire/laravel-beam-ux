<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One next step on a dashboard's {@see DashboardWelcomeData} panel: a label and a host-relative href
 * the host actually routes. `key` names the step (`create-team`, `settings`) so a client or a test can
 * address it without matching on copy.
 */
#[TypeScript]
class DashboardWelcomeActionData extends BeamData
{
    public function __construct(
        public string $key,
        public string $label,
        public string $href,
    ) {}
}
