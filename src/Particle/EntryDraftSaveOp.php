<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryDraftInputData;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * `POST /beam-ux-entries/{id}/save-draft` — record an edited body WITHOUT publishing it.
 *
 * The draft half of the pair {@see EntryBodySaveOp} was until now the whole of: that op writes AND
 * publishes in one act, which is the right shape for "save and it is live" and the wrong one for an
 * author who wants to work before anyone reads it. This op writes the same body through the same
 * particle pipeline and records it as a version, and then stops — no disk mirror, no compile, no move
 * of the publication pin. A guest keeps reading the published body, because the artifact they are
 * served is addressed by that pin and nothing has touched it.
 *
 * The reason this is not a flag on the existing op: `save-body` is what three editor surfaces and one
 * browser journey already call, and its contract is "this is live now". Widening it with a `draft: true`
 * would have made a write's VISIBILITY depend on an optional field, so a client that forgot the field
 * would publish — the failure mode pointing the wrong way. Two operations, two abilities to declare,
 * two names in `route:list`.
 *
 * Everything else is shared with the publish path and lives in {@see EntryPublication}: the
 * canvas-on-a-foreign-format refusal (422 on `body`, before anything is written), the first-write
 * particle binding, and the baseline that records the already-live body as a version before the draft
 * overwrites it.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'save-draft',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Entitlement plane, subject-free — the same declaration every sibling on this resource carries
    // (particle-operation-surface ticket 08). Draft, publish and the immediate-publish write MUST stay
    // on one plane: an author who can save a page live but not save a draft of it has been handed the
    // more dangerous half of the pair.
    abilityModel: false,
    input: EntryDraftInputData::class,
    output: EntryPublicationData::class,
)]
class EntryDraftSaveOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        $input = EntryDraftInputData::validateAndCreate($request->all());

        return app(EntryPublication::class)->recordDraft($model, $input->body, $input->label);
    }
}
