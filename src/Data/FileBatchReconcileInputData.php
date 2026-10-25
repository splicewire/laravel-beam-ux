<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

#[TypeScript]
final class FileBatchReconcileInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    /** @param list<array{memberId: string, expectedHeadVersion: string, expectedPublishedVersion: string, expectedFileExists: bool, expectedFileSha256: ?string, fingerprint: string}> $items */
    public function __construct(public array $items, public bool $confirmed) {}

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
