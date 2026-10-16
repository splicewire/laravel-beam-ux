<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntrySourceSaveOp;

/**
 * The declared payload of {@see EntrySourceSaveOp} — ADR-0228 d10's **Source** input: `{ source }`.
 *
 * A Source-kind entry (a component, an mdx page until ticket 15) is authored as text; this is that text,
 * which the entry's codec encodes into the stored body. Blocks and form kinds have their own typed
 * inputs ({@see BeamUxEntryBodyInputData} / the theme form), so NO operation takes a bare `array` — the
 * axis d1 declares decides the input. `source` is `present` rather than `required` so an author may clear
 * a document to the empty string.
 */
#[TypeScript]
class BeamUxEntrySourceInputData extends BeamData
{
    public function __construct(
        #[Description(
            'The raw source text to persist — the author\'s editor buffer. The entry\'s codec encodes it '.
            'into the stored body (for a tsx page the server lowers it to blocks, ADR-0228 d9). `present` '.
            'allows the empty string, which clears the source.'
        )]
        public string $source,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'source' => ['present', 'string'],
        ];
    }
}
