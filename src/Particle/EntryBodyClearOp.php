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
 * Record an empty draft on the entry's existing VersionStore history (ADR-0225 d3/d8).
 * Publish remains separate. Earlier bodies stay reachable through History, and activity is audit only.
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
