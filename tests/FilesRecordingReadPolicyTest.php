<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Schemastud\Frame\Http\Controllers\FrameManifestController;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Data\ReleaseFilesData;
use Splicewire\Beam\Ux\Recording\FilesRecordingReadPolicy;

class FilesRecordingReadPolicyTest extends TestCase
{
    public function test_metadata_and_addressed_detail_keep_authority_without_opening_unaddressed_reads(): void
    {
        Gate::define('ux.author', fn (User $user): bool => $user->getAuthIdentifier() === 1);
        app(ParticleResourceRegistry::class)->registerClass(ReleaseFilesData::class);
        $resource = app(ParticleResourceRegistry::class)->get('beam-ux-release-files');
        $author = (new User)->forceFill(['id' => 1]);
        $reader = (new User)->forceFill(['id' => 2]);
        $policy = new FilesRecordingReadPolicy;
        $manifest = Request::create('/manifest');
        $manifest->setRouteResolver(fn () => new Route('GET', '/manifest', FrameManifestController::class));
        $this->assertTrue($policy->inspect($author, $resource, $manifest)->allowed());
        $this->assertTrue($policy->inspect($reader, $resource, $manifest)->denied());
        $this->assertTrue($policy->inspect(null, $resource, $manifest)->denied());

        $detail = Request::create('/records/member');
        $route = new Route('GET', '/records/{id}', [FrameResourceController::class, 'show']);
        $route->bind($detail);
        $detail->setRouteResolver(fn () => $route);
        $this->assertTrue($policy->inspect($author, $resource, $detail)->allowed());
        $this->assertTrue($policy->inspect($reader, $resource, $detail)->denied());
        $this->assertTrue($policy->inspect(null, $resource, $detail)->denied());

        foreach (['index', 'summary', 'filters'] as $method) {
            $request = Request::create('/records?id=member&controller='.urlencode(FrameManifestController::class));
            $request->headers->set('X-Controller', FrameManifestController::class);
            $route = new Route('GET', '/records', [FrameResourceController::class, $method]);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $this->assertTrue($policy->inspect($author, $resource, $request)->denied(), $method);
        }
        $this->assertTrue($policy->inspect($author, $resource, Request::create('/records/member'))->denied());
    }
}
