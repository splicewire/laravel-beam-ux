<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\RedirectTracesData;
use Splicewire\Beam\Ux\Data\RedirectTracesInputData;
use Splicewire\Beam\Ux\Redirect\RedirectTraces;

/**
 * Where each of the site's Redirects leads a guest (owner-review-20261102 research 37, 37-T1): the walk, its end, its
 * hops and whether it needs attention. Scaffolded by `splicewire:beam:make:particle-op`; subject-free, so a host mounts
 * it above the Redirects resource's `{id}` routes (particle doctrine: a NoSubject op shares the `{uri}/{id}` slot shape).
 */
#[ParticleOp(
    resource: 'beam-ux-redirect',
    name: 'traces',
    summary: 'Walk every Redirect as a guest and report where it ends',
    description: 'One walk per Redirect by the renderer\'s own resolver: answered (with its hops), or a loop, too long, a dead end, gone, readers-only or shadowed by a page.',
    kind: OperationKind::Read,
    ability: 'ux.author',
    abilityModel: false,
    affordance: false,
    input: RedirectTracesInputData::class,
    output: RedirectTracesData::class,
    subject: NoSubject::class,
    method: HttpMethod::Get,
)]
final class RedirectTracesOp
{
    public static function handle(mixed $subject, Request $request, mixed $actor): RedirectTracesData
    {
        $input = RedirectTracesInputData::validateAndCreate($request->all());

        return new RedirectTracesData(app(RedirectTraces::class)->all($input->realm));
    }
}
