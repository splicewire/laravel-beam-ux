<?php

namespace Splicewire\Beam\Ux\Analytics;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Schemastud\Frame\Authorization\ResourceAuthorizer;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Particle\ListRouteName;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Frame\DefaultDashboardRealm;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The pages the Site area reports on and links to: beam-ux's own resources (site health, sitemap health, redirects),
 * read and linked through the seams their own pages use, so a figure cannot disagree with its page and no link is dead.
 *
 * A **read** ({@see rows()}) is the destination's own list for this request's viewer, in the realm this request serves:
 * frame's reach check ({@see ResourceAuthorizer::authorizeAccess()}, as the list route asks), the resource's read guard
 * ({@see ResourceReadGuard::bindReadContext()}, as a server-side Collection read asks), then the resource's own frame
 * handler with only the given `filter[…]`, which applies its query, its projection and its projected filters. A
 * refusal anywhere answers null, and the area then draws no figure for it: an honest absence, never an invented zero.
 *
 * A **link** ({@see href()}) is the destination's list leaf by its own route name ({@see ListRouteName}), only where this
 * realm's route context binds that name (the route exists) and the rail would show the viewer that leaf: the same
 * listable and seat-gate checks the rail's resource children pass. Otherwise null.
 *
 * The realm is the one the frame route serving this request carries (`defaults['realm']`), which is how frame's own
 * resource controller binds a handler to its realm, resolved through {@see DefaultDashboardRealm} as the manifest's nav
 * resolves it, so the links are the leaves of the rail this viewer is drawn. With no realm at all, nothing is read.
 */
final class SiteDestinations
{
    public function __construct(
        private readonly ResourceRegistry $frame,
        private readonly ParticleResourceRegistry $particles,
        private readonly ResourceAuthorizer $authorizer,
        private readonly ResourceReadGuard $readGuard,
        private readonly RealmRegistry $realms,
        private readonly RouteContextProjector $routes,
        private readonly ResourceVisibility $visibility,
        private readonly SeatGate $seatGate,
    ) {}

    /**
     * The rows `$key`'s own list returns to this viewer here, filtered by `$filter` (`filter[name] => value`), or null when
     * this viewer may not read that list in this realm.
     *
     * @param  array<string, mixed>  $filter
     * @return list<array<string, mixed>>|null
     */
    public function rows(string $key, array $filter = []): ?array
    {
        $realm = $this->realm();
        if ($realm === null || ! $this->frame->has($key) || ! $this->particles->has($key)) {
            return null;
        }

        $request = request();
        $read = Request::create('/', 'GET', $filter === [] ? [] : ['filter' => $filter]);
        $read->setUserResolver(fn () => $request->user());

        try {
            $this->authorizer->authorizeAccess($this->frame->get($key));
            $resource = $this->particles->get($key);
            $this->readGuard->bindReadContext($resource, $read);

            return array_values(app(ParticleFrameResourceHandler::class)->forRequest($read, $realm)
                ->index($resource->toResourceDefinition(), []));
        } catch (AuthorizationException|HttpExceptionInterface) {
            return null;
        }
    }

    /** The relative href of `$key`'s list page for this viewer in this realm, or null when it has no live leaf here. */
    public function href(string $key): ?string
    {
        $realm = $this->realm();
        if ($realm === null || ! $this->frame->has($key) || $this->realms->tryResolve($realm) === null) {
            return null;
        }

        $definition = $this->frame->get($key);
        $routeName = ListRouteName::of($definition);

        try {
            $href = $this->routes->hrefs($realm)[$routeName] ?? null;
        } catch (Throwable) {
            return null; // a realm whose route context cannot be projected binds no leaf
        }

        return is_string($href) && $this->railShows($definition, $routeName, $realm, request()->user()) ? $href : null;
    }

    /** The checks the rail's resource children pass before they are drawn (FrameResourcesInvocable::resourceViewable). */
    private function railShows(\Schemastud\Frame\Registry\ResourceDefinition $definition, string $routeName, string $realm, ?Authenticatable $user): bool
    {
        if (! $this->visibility->listable($definition, $user, $realm)) {
            return false;
        }

        $resolution = $this->seatGate->resolve($routeName, $realm);

        return $resolution === null || $this->seatGate->allows($resolution, $user, $realm);
    }

    private function realm(): ?string
    {
        $realm = request()->route()?->defaults['realm'] ?? null;
        $realm = app(DefaultDashboardRealm::class)->resolve(is_string($realm) && $realm !== '' ? $realm : null, request());

        return is_string($realm) && $realm !== '' ? $realm : null;
    }
}
