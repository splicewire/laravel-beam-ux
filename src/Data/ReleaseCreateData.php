<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

#[TypeScript, Title('Create Release')]
final class ReleaseCreateData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Description('A name for the new Release, shown wherever it is listed.')]
        public string $title,
    ) {}

    public static function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255']];
    }
}
