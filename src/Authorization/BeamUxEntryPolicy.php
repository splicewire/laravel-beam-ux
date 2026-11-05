<?php

namespace Splicewire\Beam\Ux\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * Central entries are part of the operator's authoring surface (`ux.author`); tenant entries
 * retain the existing permission-cascade policy (`site.edit` and its grant/reach rules).
 */
final class BeamUxEntryPolicy extends ConfiguredModelPolicy
{
    public function viewAny(Authenticatable $user)
    {
        return $this->isCentralMount() ? Gate::forUser($user)->allows('ux.author') : parent::viewAny($user);
    }

    public function view(?Authenticatable $user, Model $instance)
    {
        return $this->isCentralMount() ? Gate::forUser($user)->allows('ux.author') : parent::view($user, $instance);
    }

    public function create(Authenticatable $user)
    {
        return $this->isCentralMount() ? Gate::forUser($user)->allows('ux.author') : parent::create($user);
    }

    public function update(Authenticatable $user, Model $instance)
    {
        return $this->isCentralMount() ? Gate::forUser($user)->allows('ux.author') : parent::update($user, $instance);
    }

    public function delete(Authenticatable $user, Model $instance)
    {
        return $this->isCentralMount() ? Gate::forUser($user)->allows('ux.author') : parent::delete($user, $instance);
    }

    public function restore(Authenticatable $user, Model $instance)
    {
        return $this->isCentralMount() ? Gate::forUser($user)->allows('ux.author') : parent::restore($user, $instance);
    }

    public function forceDelete(Authenticatable $user, Model $instance)
    {
        return $this->isCentralMount() ? Gate::forUser($user)->allows('ux.author') : parent::forceDelete($user, $instance);
    }

    /**
     * The operator realm is positively marked on its server-owned route defaults. Absence of a
     * tenant marker is not evidence of a central mount: legacy tenant sockets are realm-agnostic.
     * Active tenancy always wins and preserves the cascade even if a route was marked operator.
     */
    private function isCentralMount(): bool
    {
        if (app(TenancyContext::class)->isActive()) {
            return false;
        }

        $route = request()->route();
        $defaults = $route?->defaults ?? [];

        return ($defaults['realm'] ?? null) === 'operator'
            || ($defaults[ParticleOperationController::REALM] ?? null) === 'operator';
    }
}
