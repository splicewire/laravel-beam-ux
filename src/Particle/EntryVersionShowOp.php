<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\EntryVersionShowInputData;
use Splicewire\Beam\Ux\Data\EntryVersionSnapshotData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryError;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * `GET /beam-ux-entries/{id}/version?ref=…` — read ONE recorded version of the entry with the frozen snapshot it
 * holds, so the document editor can show what a version held (particle-editor G3, owner-ruled with this sibling).
 *
 * It is the singular beside {@see EntryVersionsShowOp}: the list answers identity and provenance for every version,
 * this answers the frozen tree for one. Nothing is forked: the read goes through the same {@see VersionStore} the
 * restore and preview ops read, scoped to this entry's particle.
 *
 * **An unknown ref is a 422 on `ref`, not a 404** (coord ruling Q1, pe09). That is the convention of this resource's
 * own sibling {@see EntryVersionRestoreOp}: the entry exists and the request is well formed, the named version simply
 * is not one of its own, and scoping the lookup to the entry's particle keeps one entry's history from being
 * addressable from another's. An entry that has no particle yet, or a host without the version store, has no versions,
 * so it answers the same 422 (never a query on a missing table).
 * (The record-agnostic `GET {resource}/{id}/versions/{ref}` in laravel-beam-versioning answers 404 instead; that is
 * the macro's convention, not this resource's.)
 *
 * GET with a declared input: `ref` arrives in the query string, the one axis a GET op reads. It is mounted by
 * {@see \Splicewire\Beam\Ux\Support\EditorRoutes::mount()} beside `versions` and `restore`, so every host that mounts
 * the editor reaches it (the generator's "mount it" step, done in the package).
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'version',
    summary: 'Read one entry version with its snapshot',
    description: 'One recorded version of the entry, by version uuid or readable handle (`ref`), with the frozen snapshot it holds. A ref that is not one of this entry\'s versions is a 422 on `ref`.',
    kind: OperationKind::Read,
    ability: 'ux.author',
    // Entitlement plane, subject-free — the same plane as every sibling on this resource. An author who can list
    // the history but not open one version of it would be reading a list they cannot use.
    abilityModel: false,
    affordance: false,
    input: EntryVersionShowInputData::class,
    output: EntryVersionSnapshotData::class,
    method: HttpMethod::Get,
)]
class EntryVersionShowOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): EntryVersionSnapshotData
    {
        // The controller has validated the declared input; validateAndCreate keeps a direct call honest too, as the
        // siblings do.
        $input = EntryVersionShowInputData::validateAndCreate($request->all());

        // A host without the version store has no versions to name: the same clean 422 on `ref`, never a query on a
        // missing table (the list sibling degrades to an empty history there).
        $publication = app(EntryPublication::class);
        $particle = $publication->recordsVersions() ? $model->particle : null;
        $store = app(VersionStore::class);
        $version = $particle === null ? null : $store->version($particle, $input->ref);

        if ($particle === null || $version === null) {
            throw CodedValidationException::for('ref', EntryError::VersionNotFound, ['ref' => $input->ref]);
        }

        return EntryVersionSnapshotData::fromVersion(
            $version,
            isHead: (string) $version->id === (string) $store->head($particle)?->id,
            isPublished: (string) $version->id === (string) $publication->publishedVersionId($model),
        );
    }
}
