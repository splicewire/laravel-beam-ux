<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryBodyClearOp;

/**
 * The declared `input:` shape of {@see EntryBodyClearOp} — the optional annotation, and nothing else.
 *
 * A clear carries NO body, for the same reason a publish carries none: the entry is addressed by `{id}`
 * on the route, and what is removed is whatever that entry holds. Accepting a body would make "clear"
 * and "save `[]`" two spellings of one act, and they are deliberately not the same act — saving an
 * empty document is an authored, recorded, published empty body; clearing returns the entry to "never
 * authored".
 */
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
