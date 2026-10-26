<?php

namespace Splicewire\Beam\Ux\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Frame\ParticleResourceActions;
use Splicewire\Beam\Http\Particle\ParticleOperationController;

/** The single author check for editor handlers and projections, honoring only a server-tagged tenant mount. */
final class EditorAuthorizer
{
    public function allows(?Authenticatable $actor, ?Request $request = null): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        $ability = $this->ability($request);

        return $ability === 'ux.author'
            ? Gate::forUser($actor)->allows('ux.author')
            : app(AbilityResolver::class)->allows($actor, $ability);
    }

    public function authorize(?Authenticatable $actor, ?Request $request = null): void
    {
        $ability = $this->ability($request);
        if ($ability === 'ux.author') {
            Gate::forUser($actor)->authorize('ux.author');

            return;
        }

        Gate::forUser($actor)->authorize(app(AbilityResolver::class)->entitlementAbility($ability));
    }

    private function ability(?Request $request): string
    {
        $route = ($request ?? request())->route();
        $defaults = $route instanceof Route ? $route->defaults : [];
        $realm = $defaults[ParticleOperationController::REALM] ?? null;
        $ability = 'ux.author';

        if ($realm === 'tenant') {
            $overrides = $defaults[ParticleOperationController::ABILITY_OVERRIDES] ?? [];
            $ability = app(ParticleResourceActions::class)->effectiveResourceReadAbility(
                'beam-ux-entry',
                'ux.author',
                'tenant',
                is_array($overrides) ? $overrides : [],
            );
        }

        return $ability;
    }
}
