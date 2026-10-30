<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('File inspection')]
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
        public bool $canRemirror = false,
        public bool $canTakeAll = false,
        /** @var list<array{memberId: string, path: string, expectedHeadVersion: string, expectedPublishedVersion: string, expectedFileExists: bool, expectedFileSha256: ?string, fingerprint: string}> */
        public array $takeAllItems = [],
    ) {}
}
