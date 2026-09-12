<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Data\EntryVersionRestoreInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * `POST /beam-ux-entries/{id}/restore` — roll the entry forward to a recorded version and publish it.
 *
 * Forward, never back. The frozen snapshot is applied onto the working copy and a NEW version of the
 * result is recorded, so the history the author is looking at is appended to rather than rewritten —
 * `rushing/laravel-versioning`'s own documented restore shape. The restored body is then published, which
 * is the part a record-agnostic restore cannot do: the pin moves, the disk mirror follows, and the
 * artifact is recompiled, so a guest reads the restored body on their next load.
 *
 * A ref that names no version of THIS entry is a 422 on `ref`, not a 404 on the entry: the entry exists
 * and the request is well-formed, the named version simply is not one of its own — and scoping the
 * lookup to the record is what keeps one entry's history from being addressable from another's.
 *
 * ⚠️ **A restore onto an empty document must read as UNAUTHORED, not as a broken page.** Restoring an
 * entry to a version whose body is `{}` retires the artifact rather than compiling a module that exports
 * nothing, so the reader gets the honest never-authored state and the host's own default page. That is
 * {@see \Splicewire\Beam\Ux\Compile\CompileEntryBody}'s behaviour (beam-ux d033815, measured across
 * beam.test, satellite.test and a fresh tower), inherited here because this path calls the same compile
 * action rather than a second one.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'restore',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Entitlement plane, subject-free — the same plane as every sibling on this resource.
    abilityModel: false,
    input: EntryVersionRestoreInputData::class,
    output: EntryPublicationData::class,
)]
class EntryVersionRestoreOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        $input = EntryVersionRestoreInputData::validateAndCreate($request->all());

        return app(EntryPublication::class)->restore($model, $input->ref, $input->label);
    }
}
