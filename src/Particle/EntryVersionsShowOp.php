<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * `GET /beam-ux-entries/{id}/versions` — the entry's recorded version history plus both pins, which is
 * everything the dock's history panel renders: the rows, which one is the working HEAD, which one a
 * guest is reading, and whether a draft is pending.
 *
 * ## Why this is not `Route::recordVersions()`
 *
 * `splicewire/laravel-beam-versioning` ships a ready-made versions triplet for any `Versionable`, and
 * `BeamUxEntry::versionable()` was annotated for it before this pass. It is not mounted, and the reason
 * is the other two verbs in the triplet rather than this one. Its `store` mints a version of the working
 * copy and its `restore` applies one and moves HEAD — both correct, both record-agnostic, and both blind
 * to the three things that have to happen for a beam-ux entry: the publication pin, the placed disk
 * mirror, and the compiled artifact. Mounted here, its restore would move the particle and leave the
 * page serving a module compiled from a body no longer in the record, and its store would mint versions
 * outside the pin's accounting — a second way to write history that the first one does not know about.
 *
 * So this package composes the STORE (`rushing/laravel-versioning`'s `VersionStore`, which beam-core
 * already hard-requires) and declares its own operations over it, next to the compile pipeline that has
 * to run with them. Nothing is forked: no second version table, no second snapshot shape, no second
 * restore primitive. A host that wants the generic REST triplet for some OTHER model still mounts the
 * macro; it is the entry-body pipeline, not the mechanism, that this op exists for.
 *
 * GET, and `input: false` (§4): the panel refetches this on every open and it takes nothing. A GET
 * carrying any query key is therefore a 422 rather than a silently-ignored parameter.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'versions',
    kind: OperationKind::Read,
    ability: 'ux.author',
    // Entitlement plane, subject-free — and the SAME plane as the three writes beside it. An author who
    // can publish but cannot list what they published would be reading a blank history panel over a
    // history they wrote.
    abilityModel: false,
    input: false,
    output: EntryPublicationData::class,
    method: HttpMethod::Get,
)]
class EntryVersionsShowOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        return app(EntryPublication::class)->state($model);
    }
}
