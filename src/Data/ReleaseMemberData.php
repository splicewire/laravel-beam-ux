<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One member of a Release, of any kind (ADR-0243). `kind` is the member kind (`entry`, or `config-override` for a config
 * section, config-sections ticket 11); `ref` is that kind's own address for its subject (an entry's id, a section's
 * root); `label` is what a reviewer reads (an entry's title, a section's title).
 *
 * `entryId` is the entry an `entry` member holds, and null for a member of any other kind, which no entry action
 * (drop, rebase, use-latest) addresses by entry.
 */
#[TypeScript, Title('Release member')]
final class ReleaseMemberData extends BeamData
{
    public function __construct(
        public ?string $entryId,
        public ?string $versionId,
        public ?string $observedPin,
        public ?string $redirectTarget,
        public bool $stale,
        public string $kind = 'entry',
        public string $ref = '',
        public string $label = '',
    ) {}
}
