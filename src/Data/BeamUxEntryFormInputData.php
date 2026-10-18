<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryFormSaveOp;

/**
 * The declared payload of {@see EntryFormSaveOp} — ADR-0228 d10's **Form** input.
 *
 * A Form-kind entry (a theme) is authored as fields, not blocks or free source: `form` is that fields
 * object (`{ canvas, site }` token tree for a theme), which the operation checks against the entry's own
 * `schema` before storing. Blocks and source kinds have their own typed inputs, so no operation takes a
 * bare `array` that no axis explains. `present` allows `{}`, which clears the form to its defaults.
 */
#[TypeScript]
#[Title('Entry form')]
class BeamUxEntryFormInputData extends BeamData
{
    /**
     * @param  array<string, mixed>  $form  the schema-shaped fields object
     */
    public function __construct(
        #[Description(
            'The form fields to persist — the object the inspector SchemaForm edited, checked against '.
            "the entry's own schema (a theme's `{ canvas, site }` token tree). Send the WHOLE object: this "
            .'is a replace, and `{}` is a legitimate value that clears the form.'
        )]
        public array $form,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'form' => ['present', 'array'],
        ];
    }
}
