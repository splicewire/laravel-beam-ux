<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Data\EntryPreviewInputData;
use Splicewire\Beam\Ux\Data\PreviewLinkData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewGrantService;
use Splicewire\Beam\Ux\Release\ReleaseService;

/** Mint a short-lived signed Preview URL for one saved entry version. */
#[ParticleOp(resource: 'beam-ux-entry', name: 'preview', kind: OperationKind::Write,
    ability: 'ux.author', abilityModel: false, input: EntryPreviewInputData::class, output: PreviewLinkData::class)]
final class EntryPreviewOp
{
    public static function handle(Model $model, Request $request, mixed $actor): PreviewLinkData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }

        /** @var BeamUxEntry $model */
        $input = EntryPreviewInputData::validateAndCreate($request->all());
        app(ReleaseService::class)->authorizeFileEdit($model, $actor);

        $version = $model->particle === null
            ? null
            : app(VersionStore::class)->version($model->particle, $input->versionId);
        if ($version === null) {
            throw ValidationException::withMessages(['versionId' => 'Choose a saved version of this entry.']);
        }
        $payload = $version->snapshot['payload'] ?? null;
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['versionId' => 'Choose a saved version with a valid body snapshot.']);
        }
        $artifacts = app(EntryArtifactStore::class);
        if (! $artifacts->hasVersion($model, (string) $version->id)) {
            app(CompileEntryBody::class)->forVersion($model, (string) $version->id, $payload);
        }

        $routeName = (string) config('beam.ux.route_name', 'beam.ux.').'site.preview.start';
        $grant = app(PreviewGrantService::class)->issue(
            entryId: (string) $model->getKey(),
            versionId: $input->versionId,
            releaseId: null,
            createdBy: (string) $actor->getAuthIdentifier(),
            expiresAt: now()->addHours(8),
        );

        return new PreviewLinkData(app(PreviewGrantService::class)->signedUrl($routeName, $grant, now()->addMinutes(10)));
    }
}
