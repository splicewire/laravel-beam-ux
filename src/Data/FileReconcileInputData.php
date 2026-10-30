<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/** The exact inspection confirmed by the user; the server never fills fresh defaults at submit. */
#[TypeScript, Title('File reconcile')]
final class FileReconcileInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        public string $expectedHeadVersion,
        public string $expectedPublishedVersion,
        public bool $expectedFileExists,
        public ?string $expectedFileSha256,
        public string $fingerprint,
        public bool $confirmed,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'expectedHeadVersion' => ['required', 'uuid'],
            'expectedPublishedVersion' => ['required', 'uuid'],
            'expectedFileExists' => ['required', 'boolean'],
            'expectedFileSha256' => ['present', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmed' => ['required', 'boolean', 'accepted'],
        ];
    }
}
