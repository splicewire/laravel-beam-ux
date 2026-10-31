<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
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
        #[Description('Working-head version UUID from the comparison you inspected. A changed head refuses the operation; reload the comparison rather than substituting a fresh version.')]
        public string $expectedHeadVersion,
        #[Description('Published version UUID from the comparison you inspected. A changed published pin refuses the operation; reload the comparison before confirming again.')]
        public string $expectedPublishedVersion,
        #[Description('Whether the local file existed at inspection. Submit that same state so a file appearing or disappearing refuses the operation.')]
        public bool $expectedFileExists,
        #[Description('Lowercase SHA-256 of the inspected local file bytes, or null when the file was absent. Send the inspected value unchanged to detect file changes.')]
        public ?string $expectedFileSha256,
        #[Description('Inspection fingerprint binding the entry identity, format, codec and local file placement. Submit it unchanged; a changed target requires a new comparison.')]
        public string $fingerprint,
        #[Description('Explicitly confirm the selected take-to-draft, restore-missing-file or remirror operation after reviewing the comparison. Must be true; stale version, placement and file checks still apply.')]
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
