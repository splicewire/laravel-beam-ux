<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Keep literal JsonDoc text intact; ordinary host inputs retain Laravel's null conversion. */
final class PreservesJsonDocStrings
{
    public static function matches(Request $request): bool
    {
        try {
            $route = $request->route();
            if (! $route instanceof Route) {
                // Global middleware runs before route dispatch. Use the router's validators in
                // native precedence order, without binding parameters/models or invoking a controller.
                $routes = app('router')->getRoutes()->get($request->getMethod());
                $routes = array_merge(
                    array_filter($routes, fn (Route $candidate) => ! $candidate->isFallback),
                    array_filter($routes, fn (Route $candidate) => $candidate->isFallback),
                );
                foreach ($routes as $candidate) {
                    if ($candidate->matches($request)) {
                        $route = $candidate;
                        break;
                    }
                }
            }

            return $route instanceof Route
                && ($route->defaults[ParticleOperationController::RESOURCE] ?? null) === 'beam-ux-entry'
                && in_array($route->defaults[ParticleOperationController::NAME] ?? null,
                    ['save-body', 'save-draft', 'save-blocks', 'canvas-bindings', 'print-source', 'read-source', 'make-editable', 'create-component'], true);
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }
    }
}
