<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryBodyClearInputData;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * `POST /beam-ux-entries/{id}/clear-body` — remove a page's content and return the entry to the
 * unauthored (nav-pointer) state it had before anyone wrote to it.
 *
 * Scaffolded with `splicewire:beam:make:particle-op` (write kind, all three shape slots), then moved into
 * the package beside its siblings. Named for the sibling it undoes: `save-body` binds and publishes a body,
 * `clear-body` unbinds it.
 *
 * ## Why this is not `save-body {body: []}`
 *
 * Saving an empty document is a legitimate authored act and stays one: the particle stays bound, the
 * empty body is recorded and published, and the mirror writes an empty file an author chose to have.
 * Until this op existed it was also the only way to "remove" content, so the G2 authoring journey
 * "restored" pages that way and left every host holding bound `[]` particles and 0-byte mirror files
 * (`compile-on-save-tower/FINDINGS.md`). Clearing is the act that nothing could express: the stored
 * body, its mirror file and its compiled artifact are all removed, and the entry reads exactly as an
 * entry nobody has authored. {@see EntryPublication::clear()} carries the steps and the versioning
 * reasoning.
 *
 * ## The gate is the publish gate
 *
 * A clear changes what every reader of the page is served, the moment it returns — the same thing
 * `save-body`, `publish` and `restore` do, and the same thing the workflow `transition` op does to
 * visibility. All of them declare `ux.author` on the subject-free entitlement plane, so this op declares
 * exactly that: an author who may publish a body may withdraw one, and nobody else may do either. The
 * workflow marking is deliberately left where it is — see {@see EntryPublication::clear()} for why a
 * clear is a body change and not a visibility transition.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'clear-body',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Entitlement plane, subject-free — the declaration every sibling on this resource carries
    // (particle-operation-surface ticket 08). Clear and publish on different planes would be an author
    // who can put content in front of readers and cannot take it away, or the reverse.
    abilityModel: false,
    input: EntryBodyClearInputData::class,
    output: EntryPublicationData::class,
)]
class EntryBodyClearOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        $input = EntryBodyClearInputData::validateAndCreate($request->all());

        return app(EntryPublication::class)->clear(
            $model,
            $input->label,
            $actor instanceof Model ? $actor : null,
        );
    }
}
