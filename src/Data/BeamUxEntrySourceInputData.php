<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
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
#[Title('Entry source')]
class BeamUxEntrySourceInputData extends BeamData
{
    public function __construct(
        #[Description(
            'The raw source text to persist — the author\'s editor buffer for a Source-kind entry (a '.
            'component, an mdx page). The entry\'s codec encodes it into the stored body. A Blocks-kind '.
            'entry (a tsx page) is refused here; it is authored as a JsonDoc through save-body. `present` '.
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
