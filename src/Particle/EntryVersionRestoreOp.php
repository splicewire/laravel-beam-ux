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
 * `POST /beam-ux-entries/{id}/restore` — roll the entry forward to a recorded version, as a DRAFT.
 *
 * Forward, never back. The frozen snapshot is applied onto the working copy and a NEW version of the
 * result is recorded, so the history the author is looking at is appended to rather than rewritten —
 * `rushing/laravel-versioning`'s own documented restore shape. It does not publish (ADR-0225 decision 5):
 * the pin, the disk mirror and the artifact stay on the published version, so a guest keeps reading it
 * until `publish`. Rolling back the live page is Restore and then Publish.
 *
 * A ref that names no version of THIS entry is a 422 on `ref`, not a 404 on the entry: the entry exists
 * and the request is well-formed, the named version simply is not one of its own — and scoping the
 * lookup to the record is what keeps one entry's history from being addressable from another's.
 *
 * ⚠️ **An empty restored version is still only a draft.** Restoring an entry to a version whose body is `{}`
 * neither compiles nor retires anything: the artifact a reader gets stays the published one. It is the LATER
 * `publish` of that draft that retires the artifact rather than compiling a module that exports nothing, so the
 * reader then gets the honest never-authored state and the host's own default page — that is
 * {@see \Splicewire\Beam\Ux\Compile\CompileEntryBody}'s behaviour (beam-ux d033815), reached through Publish.
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
