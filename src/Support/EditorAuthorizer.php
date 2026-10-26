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

        return app(AbilityResolver::class)->allows($actor, $this->ability($request));
    }

    public function authorize(?Authenticatable $actor, ?Request $request = null): void
    {
        $ability = app(AbilityResolver::class)->entitlementAbility($this->ability($request));
        Gate::forUser($actor)->authorize($ability);
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
