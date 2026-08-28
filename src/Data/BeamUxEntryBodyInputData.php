<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;

/**
 * The declared payload of {@see EntryBodySaveOp} — everything the entry-body write accepts, which is
 * one thing: the body.
 *
 * It exists because the endpoint this operation replaces accepted **any shape at all**. The retired
 * `BeamUxEntryBodyController::update()` read `(array) $request->input('body', [])` and wrote whatever
 * came back, so "what may I send here" was answerable only by reading the handler — and a client that
 * sent `{ "bodyy": … }` got a silent save of an empty document rather than a 422. ADR-0214 §1 made the
 * payload a declared `input:`, and `ParticleOperationController::validateInput()` runs it before the
 * handler is reached.
 *
 * `body` is `present` rather than `required`: an author legitimately clears a document to `{}`, and
 * `required` rejects an empty array. `present` demands the key and accepts an empty one, which is the
 * distinction this wire actually needs.
 *
 * Deliberately NOT carrying `slug`, `namespace`, `type` or `schema`. The entry is addressed by `{id}`
 * on the route (ADR-0214 §2) and everything else on {@see BeamUxEntryBodyData} is server-derived; a
 * write DTO that echoes read-only fields invites a client to believe it can change them.
 */
#[TypeScript]
class BeamUxEntryBodyInputData extends BeamData
{
    /**
     * The prose lives on `#[Description]` and NOT only in this `@param`, which is what
     * api-surface-coherence ticket 96's guard measured: `JsonSchemaGenerator` does not read docblocks,
     * so a property documented here alone reaches the reference and the generated SDK undescribed.
     *
     * @param  array<string, mixed>  $body  see the attribute
     */
    public function __construct(
        #[Description(
            'The particle body to persist — the JsonDoc the inspector SchemaForm edited. Send the '.
            'WHOLE document: this is a replace, not a merge, and `{}` is a legitimate value that '.
            'clears the entry (which is why the rule is `present` rather than `required`).'
        )]
        public array $body,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'body' => ['present', 'array'],
        ];
    }
}
