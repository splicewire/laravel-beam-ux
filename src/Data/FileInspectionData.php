<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class FileInspectionData extends BeamData
{
    public function __construct(
        public string $memberId,
        public string $entryId,
        public string $state,
        public string $message,
        public ?string $workingHeadVersion = null,
        public ?string $publishedVersion = null,
        public bool $fileExists = false,
        public ?string $fileSha256 = null,
        public ?string $fingerprint = null,
        public ?string $path = null,
        public ?string $publishedSource = null,
        public ?string $localSource = null,
        public bool $canTake = false,
        public bool $canRestore = false,
        public bool $sourceTruncated = false,
        public ?string $format = null,
    ) {}
}
