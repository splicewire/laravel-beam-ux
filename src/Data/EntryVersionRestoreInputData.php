<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryVersionRestoreOp;

/**
 * The declared payload of {@see EntryVersionRestoreOp} — which recorded version to roll forward to.
 *
 * `ref` rides the BODY rather than the path, unlike `rushing/laravel-versioning`'s own
 * `{resource}/{id}/versions/{ref}/restore`. A `#[ParticleOp]` mounts at `{uri}/{coordinates…}/{op}`
 * and its coordinates are the declared subject's `pathParameters()` — `{id}` here — so there is no
 * second path slot to put it in, and inventing one would be a fourth declaration site for a wire shape
 * (particle doctrine). The ref accepts either form the store resolves: a version uuid or a readable
 * slug like `v2`.
 */
#[TypeScript]
class EntryVersionRestoreInputData extends BeamData
{
    public function __construct(
        #[Description('The version to restore — a version uuid, or its readable handle (e.g. `v2`).')]
        #[Example('v2')]
        public string $ref,
        #[Description('An optional annotation for the version this restore records; defaults to "restore of v{n}".')]
        public ?string $label = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'ref' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
