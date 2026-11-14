<?php

namespace Splicewire\Beam\Ux\Recording;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Schemastud\Frame\Http\Controllers\FrameManifestController;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * Recording is a release-member drilldown, never a standalone list. Metadata keeps its declared
 * detail projection available; the addressed read still meets the all-member release row scope.
 */
final class FilesRecordingReadPolicy implements ResourceReadPolicy
{
    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        $authority = Gate::forUser($actor)->inspect('ux.author');
        if ($actor === null || $authority->denied()) {
            return Response::deny();
        }

        $route = $request->route();
        if (! $route instanceof Route) {
            return Response::deny(RecordingError::AddressedMemberRequired->message(), RecordingError::AddressedMemberRequired->code());
        }

        $controller = $route->getControllerClass();
        if (is_string($controller) && is_a($controller, FrameManifestController::class, true)) {
            return $authority;
        }

        $id = $route->parameters['id'] ?? null;

        return $route->getActionMethod() === 'show' && is_string($id) && $id !== ''
            ? $authority
            : Response::deny(RecordingError::AddressedMemberRequired->message(), RecordingError::AddressedMemberRequired->code());
    }
}
