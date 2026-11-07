<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A central page's mirror file ({@see MirrorStatusRowData}'s verdict) and the Release member a file repair addresses.
 * Never projected on a tenant's connection (D-37-d).
 */
#[TypeScript, Title('Site map file')]
final class SiteMapFileData extends BeamData
{
    public function __construct(
        #[Description('The mirror verdict: clean, modified, untracked, untracked-ignored, not-written, no-git-repo, mirror-disabled, not-yet-saved.')]
        public string $state,
        public ?string $path,
        public bool $exists,
        public ?string $lastModifiedAt,
        #[Description('The member of the newest published Release holding this page at the version readers see; the file repair ops address it.')]
        public ?string $repairMemberId,
        public ?string $repairReleaseId,
        #[Description('Why no repair is addressable: `no-release` (no published Release holds the page) or `pin-moved` (the newest one froze another version).')]
        public ?string $repairBlocked,
    ) {}
}
