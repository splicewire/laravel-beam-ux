<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One reason Publish would refuse a Release, or one note that doesn't stop it (ADR-0232 d11). `fixes` names the actions
 * that resolve it, most direct first: `submit` and `approve` move the Release; `rebase` and `drop` act on the member
 * (the Release command's actions); `add` puts `componentId` into the Release; `open` opens `entryId` in the editor.
 */
#[TypeScript, Title('Release readiness item')]
final class ReleaseReadinessItemData extends BeamData
{
    /** @param list<string> $fixes */
    public function __construct(
        public string $code,
        public ?string $entryId,
        public string $message,
        public array $fixes = [],
        public ?string $componentId = null,
        public ?string $releaseId = null,
        public ?string $releaseTitle = null,
        public ?string $releaseStatus = null,
        /** When that other Release is scheduled to publish (ISO 8601), and in which timezone; null when it isn't. */
        public ?string $releaseDueAt = null,
        public ?string $releaseTimezone = null,
    ) {}
}
