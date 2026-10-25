<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\ReleaseCommandData;
use Splicewire\Beam\Ux\Data\ReleaseData;
use Splicewire\Beam\Ux\Release\ReleaseService;

#[ParticleOp(resource: 'beam-ux-release', name: 'command', kind: OperationKind::Write,
    ability: 'ux.author', abilityModel: false, input: ReleaseCommandData::class, output: ReleaseData::class)]
final class ReleaseCommandOp
{
    public static function handle(Model $model, Request $request, mixed $actor): ReleaseData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }
        $input = ReleaseCommandData::validateAndCreate($request->all());
        $service = app(ReleaseService::class);
        match ($input->action) {
            'member' => $service->putMember($model, $input->entryId, $input->ref, $actor, $input->redirectTarget),
            'drop' => $service->dropMember($model, $input->entryId, $actor),
            'rebase', 'use-latest' => $service->rebase($model, $input->entryId, $actor, $input->action === 'use-latest'),
            'schedule' => $service->schedule($model, $input->dueAt, $input->timezone, $actor),
            'links-on', 'links-off' => $service->previewLinks($model, $input->action === 'links-on', $actor),
            'rotate-links' => $service->rotatePreviewLinks($model, $actor),
            'rollback' => $model = $service->rollback($model, $actor),
            default => $service->transition($model, $input->action, $actor),
        };

        return ReleaseData::project($model->fresh());
    }
}
