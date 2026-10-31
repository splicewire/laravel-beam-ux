<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

#[TypeScript, Title('File batch reconcile')]
final class FileBatchReconcileInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    /** @param list<array{memberId: string, expectedHeadVersion: string, expectedPublishedVersion: string, expectedFileExists: bool, expectedFileSha256: ?string, fingerprint: string}> $items */
    public function __construct(
        #[Description('Complete inspected batch of 1–50 changed Release files, each with its member ID, working-head and published version UUIDs, existing-file SHA-256 and target fingerprint. Copy the comparison snapshot unchanged; a changed member or file refuses the batch.')]
        public array $items,
        #[Description('Explicitly confirm importing every inspected changed file into drafts. Must be true; all version, placement and file checks still apply, and published versions remain unchanged.')]
        public bool $confirmed,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:memberId,expectedHeadVersion,expectedPublishedVersion,expectedFileExists,expectedFileSha256,fingerprint'],
            'items.*.memberId' => ['required', 'uuid'],
            'items.*.expectedHeadVersion' => ['required', 'uuid'],
            'items.*.expectedPublishedVersion' => ['required', 'uuid'],
            'items.*.expectedFileExists' => ['required', 'accepted'],
            'items.*.expectedFileSha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'items.*.fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmed' => ['required', 'boolean', 'accepted'],
        ];
    }
}
