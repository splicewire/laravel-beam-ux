<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryVersionShowOp;

/**
 * The declared input of {@see EntryVersionShowOp} — which recorded version to read. Like
 * {@see EntryVersionRestoreInputData}, `ref` is not a path slot: a `#[ParticleOp]` mounts at `{uri}/{id}/{op}` and
 * has no second path parameter, so it rides the GET query string. It accepts either form the store resolves: a
 * version uuid or a readable slug like `v2`.
 */
#[TypeScript]
#[Title('Entry version to read')]
class EntryVersionShowInputData extends BeamData
{
    public function __construct(
        #[Description('The version to read — a version uuid, or its readable handle (e.g. `v2`).')]
        #[Example('v2')]
        public string $ref,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'ref' => ['required', 'string', 'max:255'],
        ];
    }
}
