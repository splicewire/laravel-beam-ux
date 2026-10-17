<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Optional annotation for an empty draft. Clear keeps the existing particle and History. */
#[TypeScript]
class EntryBodyClearInputData extends BeamData
{
    public function __construct(
        #[Description('An optional annotation for the version that records the clear, e.g. "retired page".')]
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
