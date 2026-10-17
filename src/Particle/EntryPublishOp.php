<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Data\EntryPublishInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * `POST /beam-ux-entries/{id}/publish` — make the entry's working copy the body guests read.
 *
 * It carries no body ({@see EntryPublishInputData}): what it publishes is what the author has already
 * drafted, addressed by `{id}` on the route. It moves the publication pin to the working copy's version,
 * mirrors that body to its placed disk file, and compiles the artifact at the address the pin names —
 * the real request-path compile, the same {@see \Splicewire\Beam\Ux\Compile\CompileEntryBody} action a
 * save and the console backfill both call, so "published" means an artifact exists and not merely that
 * a column changed.
 *
 * A publish straight after a draft pins THAT draft's version rather than minting a twin of it; a publish
 * over a working copy some other writer moved (a disk import, a backfill) records the working copy first,
 * so the thing pinned is always a version that froze exactly what is being served.
 *
 * ADR-0232 d7: compile a missing named artifact before changing the pin. A failed compile
 * refuses publication; a compiled draft is reused without invoking the compiler again.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'publish',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Entitlement plane, subject-free — see {@see EntryDraftSaveOp}'s note. Draft and publish on
    // different planes would be an editor that can write a draft it can never ship.
    abilityModel: false,
    input: EntryPublishInputData::class,
    output: EntryPublicationData::class,
)]
class EntryPublishOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        $input = EntryPublishInputData::validateAndCreate($request->all());

        return app(EntryPublication::class)->publishVersion($model, $input->ref, $actor instanceof Model ? $actor : null);
    }
}
