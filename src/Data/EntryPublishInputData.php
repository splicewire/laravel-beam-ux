<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryPublishOp;

/**
 * The declared payload of {@see EntryPublishOp} — the optional annotation, and nothing else.
 *
 * A publish carries NO body on purpose. What it publishes is the working copy the author has already
 * saved, addressed by the entry on the route; accepting a body here would give the wire two ways to
 * write one and let a client publish something it never drafted. The write op and the publish op are
 * therefore not interchangeable, which is the distinction this whole pair exists to make.
 */
#[TypeScript]
class EntryPublishInputData extends BeamData
{
    public function __construct(
        #[Description('An optional annotation for the version this publish pins, e.g. "launch copy".')]
        public ?string $label = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
