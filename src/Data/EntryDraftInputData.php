<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryDraftSaveOp;

/**
 * The declared payload of {@see EntryDraftSaveOp} — a body to record as a DRAFT, plus the optional
 * human annotation that rides the version it mints.
 *
 * It is deliberately a sibling of {@see BeamUxEntryBodyInputData} rather than an extension of it: the
 * two ops take the same `body` under the same `present`-not-`required` rule (an author may legitimately
 * clear a document), and only this one carries a label, because only this one mints a version the
 * author is naming. A shared parent would have put a `label` on the publish-immediately write, where it
 * means nothing.
 */
#[TypeScript]
class EntryDraftInputData extends BeamData
{
    /**
     * @param  array<string, mixed>  $body  see the attribute
     */
    public function __construct(
        #[Description(
            'The particle body to record as a draft. Send the WHOLE document: this is a replace, not '.
            'a merge, and an empty document is a legitimate value (which is why the rule is `present` '.
            'rather than `required`). It is written to the working copy and recorded as a version, '.
            'but NOT compiled — readers keep the published body until a publish moves the pin.'
        )]
        public array $body,
        #[Description('An optional annotation for the version this draft records, e.g. "before the rewrite".')]
        public ?string $label = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'body' => ['present', 'array'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
