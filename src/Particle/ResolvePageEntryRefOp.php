<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\PageEntryRefData;
use Splicewire\Beam\Ux\Data\PageEntryRefInputData;
use Splicewire\Beam\Ux\Support\PageEntryRef;

/**
 * Resolve the kit's namespaced page row before opening its id-addressed editor.
 *
 * This is deliberately a subject-free GET: a slug locates a row for an author, but never authorizes a
 * write and never creates the missing row. `EntryMakeEditableOp` remains the explicit creation door.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'resolve-ref',
    kind: OperationKind::Read,
    ability: 'ux.author',
    abilityModel: false,
    input: PageEntryRefInputData::class,
    output: PageEntryRefData::class,
    subject: NoSubject::class,
    method: HttpMethod::Get,
)]
final class ResolvePageEntryRefOp
{
    public static function handle(mixed $subject, Request $request, mixed $actor): ?PageEntryRefData
    {
        $input = PageEntryRefInputData::validateAndCreate($request->all());
        $ref = PageEntryRef::for($input->slug);

        return $ref === null ? null : PageEntryRefData::from($ref);
    }

    public static function respond(mixed $payload, mixed $subject): mixed
    {
        // Laravel turns a controller's bare null into an empty response. The wire contract is JSON null,
        // distinct from an absent response, so a kit can branch without inventing its own status shape.
        return $payload === null ? new JsonResponse('null', json: true) : $payload;
    }
}
