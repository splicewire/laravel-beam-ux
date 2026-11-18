<?php

namespace Splicewire\Beam\Ux\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Frame\ParticleResourceActions;
use Splicewire\Beam\Http\Particle\ParticleOperationController;

/**
 * The single author check for editor handlers and projections, honoring only a server-tagged tenant mount.
 *
 * A tenant mount's `abilityOverrides` TARGET (`ux.author` → `site.edit`) is asked as ITSELF — the actor's
 * `can('<permission>')` — exactly as laravel-beam's AbilityResolver asks it for the ops on the same mount (launch 72,
 * root Option A; this is 72b). An `entitlement:`-prefixed target, asked as itself, IS the entitlement plane. The
 * un-overridden `ux.author` path is unchanged: the raw Gate here, the resolver's entitlement plane in allowsResolved().
 */
final class EditorAuthorizer
{
    public function allows(?Authenticatable $actor, ?Request $request = null): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        $ability = $this->ability($request);

        if ($ability === 'ux.author') {
            return Gate::forUser($actor)->allows('ux.author');
        }

        return app(AbilityResolver::class)->allows($actor, $ability, null, overridden: true);
    }

    /** Preserve callers whose pre-realm author check used AbilityResolver rather than the raw Gate. */
    public function allowsResolved(?Authenticatable $actor, ?Request $request = null): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        $ability = $this->ability($request);

        return app(AbilityResolver::class)->allows($actor, $ability, null, overridden: $ability !== 'ux.author');
    }

    public function authorize(?Authenticatable $actor, ?Request $request = null): void
    {
        $ability = $this->ability($request);
        if ($ability === 'ux.author') {
            Gate::forUser($actor)->authorize('ux.author');

            return;
        }

        // The override target as itself (72b); the Gate's own AuthorizationException is the denial.
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
