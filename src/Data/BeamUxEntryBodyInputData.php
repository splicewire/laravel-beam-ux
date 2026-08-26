<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
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
class BeamUxEntryBodyInputData extends Data
{
    /**
     * @param  array<string, mixed>  $body  the particle body to persist — the JsonDoc the inspector
     *                                      SchemaForm edited, written through beam-core's versioned
     *                                      `ParticleWriter` (ADR-0092: the surface is beam-ux's, the
     *                                      particle it rides is beam-core's)
     */
    public function __construct(
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
