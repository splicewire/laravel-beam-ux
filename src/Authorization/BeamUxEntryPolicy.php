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
        return $this->isTenantContext() ? parent::viewAny($user) : Gate::forUser($user)->allows('ux.author');
    }

    public function view(?Authenticatable $user, Model $instance)
    {
        return $this->isTenantContext() ? parent::view($user, $instance) : Gate::forUser($user)->allows('ux.author');
    }

    public function create(Authenticatable $user)
    {
        return $this->isTenantContext() ? parent::create($user) : Gate::forUser($user)->allows('ux.author');
    }

    public function update(Authenticatable $user, Model $instance)
    {
        return $this->isTenantContext() ? parent::update($user, $instance) : Gate::forUser($user)->allows('ux.author');
    }

    public function delete(Authenticatable $user, Model $instance)
    {
        return $this->isTenantContext() ? parent::delete($user, $instance) : Gate::forUser($user)->allows('ux.author');
    }

    public function restore(Authenticatable $user, Model $instance)
    {
        return $this->isTenantContext() ? parent::restore($user, $instance) : Gate::forUser($user)->allows('ux.author');
    }

    public function forceDelete(Authenticatable $user, Model $instance)
    {
        return $this->isTenantContext() ? parent::forceDelete($user, $instance) : Gate::forUser($user)->allows('ux.author');
    }

    /** The active tenancy port covers API/editor routes without Frame's realm route default. */
    private function isTenantContext(): bool
    {
        $route = request()->route();

        return app(TenancyContext::class)->isActive()
            || ($route !== null
                && ($route->defaults[ParticleOperationController::REALM] ?? null) === 'tenant');
    }
}
