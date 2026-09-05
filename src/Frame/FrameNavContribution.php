<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Http\Request;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavNode;
use Rushing\DataNav\NavRegistry;
use Rushing\DataNav\NavTree;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Fills frame's {@see FrameNavContributor} plug — so `/frame/manifest` carries `nav` and
 * `routeContext` at any host that installs beam-ux, instead of only at the one host that
 * hand-wrote its own manifest controller.
 *
 * ## The realm is the only thing this needs from the host, and it is a route fact
 *
 * A host mounting the manifest once per realm stamps `->defaults('realm', …)` and gets a
 * realm-scoped answer; a host mounting frame's single default route stamps nothing, and the realm
 * falls back to `config('beam.ux.frame_nav.default_realm')`. Neither is discovery — the realm's
 * resource MEMBERSHIP is still `config('frame.realms')`, a list the host spells out
 * (api-surface-coherence 142).
 *
 * ## Three ways this declines, and why none of them throws
 *
 * *"Is there a realm called this here?"*, *"is a navigation registered for it?"* and *"has anything
 * been placed in it?"* are all facts about the HOST, which AGENTS.md §*A check whose answer depends
 * on the host must not throw* makes absences rather than fatals. So:
 *
 *  - an unknown realm ⇒ `null`, and the payload keeps exactly the keys it had;
 *  - a realm with no registered navigation ⇒ an EMPTY nav tree beside a real `routeContext`. This
 *    is the common case on a fresh host and it is the useful half: the router table derives from
 *    the host's realm membership and needs no navigation to exist. Declining outright here would
 *    throw away the half that works, which is precisely how a seam ships and delivers nothing.
 *
 * What DOES throw is {@see RouteContextValidator}: a duplicate route name, an unbound nav seat or a
 * non-flat mount are grammar errors in the host's own plan, gettable right without knowing the host.
 */
class FrameNavContribution implements FrameNavContributor
{
    public function __construct(
        protected RouteContextProjector $routes,
        protected RouteContextValidator $validator,
        protected NavRegistry $navigations,
        protected RealmRegistry $realms,
        protected Request $request,
    ) {}

    public function contributeNav(?string $realm): ?array
    {
        $realm ??= config('beam.ux.frame_nav.default_realm');

        if (! is_string($realm) || $realm === '') {
            return null;
        }

        // An unknown realm is a host fact, not a grammar error — `tryResolve` rather than `resolve`.
        if ($this->realms->tryResolve($realm) === null) {
            return null;
        }

        $routeContext = $this->routes->routeContext($realm);
        $nav = $this->navigation($realm);

        if ($this->navigations->tryResolve($realm) instanceof DeclaredSectionNavigation) {
            $nav = $this->pruneUnbound($routeContext, $nav);
        }

        $this->validator->assert($routeContext, $nav);

        return [
            'nav' => $nav->toArray(),
            'routeContext' => $routeContext,
        ];
    }

    /**
     * The resolved, gated, active-stamped navigation for one realm — or an empty tree where this
     * host has registered no navigation under that key. {@see NavRegistry::build()} throws
     * {@see RegistryMiss} on an unknown key, and "this host registered no navigation" is exactly
     * the host-dependent answer that must not be fatal.
     */
    protected function navigation(string $realm): NavTree
    {
        try {
            return $this->navigations->build($realm, $this->context($realm));
        } catch (RegistryMiss) {
            return NavTree::make([]);
        }
    }

    /**
     * Drop the nodes naming a route this host does not mount — but ONLY from a navigation a package
     * produced, never from one the host spelled out.
     *
     * ## Why the two are treated differently
     *
     * {@see RouteContextValidator::assert()} throws on a nav `routeName` with no RouteContext entry,
     * and {@see FrameResourcesInvocable} leans on that throw by design: its docblock argues an
     * unjoinable seat should keep a fallback href rather than vanish, because the validator will
     * catch it "before this could ship". That reasoning is sound for HOST-authored nav — the throw
     * reaches the person who wrote the offending line, at their own host, while they are building it.
     *
     * It does not survive package contribution. The identical throw becomes a 500 on `/frame/manifest`
     * caused by a package the host merely INSTALLED, over a route the host was never obliged to
     * mount. "Does this host mount that route" is a fact about the host, and the estate rule is that
     * such a check reports an absence rather than a fatal (`docs/agents/traps/audits-and-findings.md`
     * — the same shape as the event catalog whose boot-time throw made `~/Herd/tower` unbootable).
     *
     * So the validator is NOT weakened: a host that mis-spells its own routeName still gets the
     * throw, immediately, exactly as before. Only a contributed node degrades to absent.
     *
     * A `*.section` node is left alone because the validator already exempts it — a section header
     * binds to no leaf, so it is never the unbound thing.
     */
    protected function pruneUnbound(array $routeContext, NavTree $nav): NavTree
    {
        $bound = [];

        foreach ($routeContext as $entry) {
            $bound[$entry->routeName] = true;
        }

        return NavTree::make($this->keepBound($nav->items, $bound));
    }

    /**
     * @param  array<int, NavNode>  $nodes
     * @param  array<string, true>  $bound
     * @return array<int, NavNode>
     */
    private function keepBound(array $nodes, array $bound): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            $name = $node->routeName;

            if ($name !== null && ! str_ends_with($name, '.section') && ! isset($bound[$name])) {
                continue;
            }

            $kept[] = $node->stamped(
                active: $node->active,
                activeTrail: $node->activeTrail,
                children: $this->keepBound($node->children, $bound),
            );
        }

        return $kept;
    }

    /**
     * The host-vocabulary-free build context: the authenticated user, the request, and the realm in
     * the opaque attributes bag. A host whose gate stages need more (a tenant, an entitlement
     * subject) binds its own {@see FrameNavContributor} over this one — the port is the seam for
     * exactly that, and beam-ux naming a tier above itself to pre-empt it would be the defect this
     * promotion exists to remove, one tier up.
     */
    protected function context(string $realm): NavContext
    {
        return new NavContext(
            user: $this->request->user(),
            request: $this->request,
            attributes: ['realm' => $realm],
        );
    }
}
