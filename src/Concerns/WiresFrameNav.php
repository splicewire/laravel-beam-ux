<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\DataNav\NavInvocableRegistry;
use Rushing\Popcorn\Concerns\Chained;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\RouteContextPlan;

/**
 * One concern of {@see BeamUxServiceProvider}: the `nav` + `routeContext` half of
 * `/frame/manifest`, filled through frame's own {@see FrameNavContributor} plug.
 *
 * Order is DECLARED, never positional — `pint`'s Laravel preset sorts `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 *
 * ## Why the binding is opt-OUT and the plan is opt-IN
 *
 * The contributor binds by default (`beam.ux.frame_nav.enabled`), because the measured problem was
 * that no host below the flagship could get these keys at all. It is still additive by
 * construction: with no realm resolvable and no navigation registered, the contributor declines
 * and the manifest is byte-identical to before.
 *
 * The {@see RouteContextPlan}, by contrast, defaults to EMPTY. Every list in it is host IA, and a
 * package guessing one would be exactly the auto-mount vocabulary `api-surface-coherence` 141
 * retired. A host that wants IA rebinds the plan from its own provider — that is the host spelling
 * it out, in the one grammar where a placement can carry the reasoning behind it.
 */
trait WiresFrameNav
{
    #[Chained('register', order: 55)]
    protected function registerFrameNav(): void
    {
        // The EMPTY plan is the package's answer, and a host that wants IA rebinds this key from its
        // own provider. There is deliberately no config arm: a `beam.ux.frame_nav.route_context`
        // block would be vocabulary with no consumer — the flagship binds a constructed plan (its
        // lists carry docblocks a config array cannot), the starter needs no lists at all, and
        // "spell it out" (141) does not mean "spell it out twice, in two grammars".
        $this->app->bind(RouteContextPlan::class, fn (): RouteContextPlan => RouteContextPlan::empty());

        // Left UNBOUND when disabled, rather than bound to null: frame resolves the port through a
        // nullable constructor argument, so an unbound interface already means "no contributor" and
        // a null-returning binding would only add a second spelling of the same fact.
        //
        // `bind`, not `bindIf`: a host wanting its own contributor binds it from its OWN provider,
        // which runs after every package provider and therefore wins. `bindIf` would make the
        // winner depend on provider order, which is load order recorded as truth.
        if (config('beam.ux.frame_nav.enabled', true)) {
            $this->app->bind(FrameNavContributor::class, FrameNavContribution::class);
        }
    }

    /**
     * The Frame-resources collector onto data-nav's own {@see NavInvocableRegistry} — so a host
     * that declares a `section` on a resource gets that resource attached to its nav seat WITHOUT
     * writing the collector, which until now only the flagship had.
     *
     * ## Registered, never invoked, until a host points a node at it
     *
     * This is a capability registration, not a nav. Nothing expands unless the host's own
     * navigation declares an {@see \Rushing\DataNav\InvocableNavItem} naming
     * {@see FrameResourcesInvocable::NAME} — the section skeleton, and the entitlement/permission
     * vocabulary beside it, stay host IA (ADR-0092). So this is additive by construction: a host
     * with no such node sees no change.
     *
     * ## Guarded on the registry being BOUND, and it does not throw
     *
     * "Is data-nav installed here" is a fact about the host, and the estate rule is that such a
     * check reports an absence rather than a fatal (`docs/agents/traps/audits-and-findings.md`).
     * data-nav binds the registry as a SINGLETON in `packageRegistered()`, so `bound()` answers
     * true exactly when the package is installed — where the concrete class being auto-resolvable
     * would answer true either way, and hand this a throwaway registry nothing reads.
     *
     * Frame's {@see \Schemastud\Frame\Contracts\ResourceRegistry} port is guarded for the second
     * half of the same reason: the collector takes it by constructor, an unbound INTERFACE is not
     * auto-resolvable, and resolving one at boot is a fatal at boot rather than an absent nav.
     * Testbench does not auto-discover, so this is the difference between a suite that runs and a
     * package that cannot boot inside one.
     *
     * Boot, not register: the registration RESOLVES the registry, and resolving another package's
     * singleton during the register phase is how a binding gets frozen before its owner has
     * declared it.
     *
     * `register()`, not a guarded `has()` check first: the registry declares
     * `OnDuplicate::Supersede`, so a host re-registering its own collector over this one is the
     * documented swap seam, and a registration conditional on what else registered first is load
     * order recorded as truth.
     */
    #[Chained('boot', order: 55)]
    protected function bootFrameNavCollector(): void
    {
        if (! $this->app->bound(NavInvocableRegistry::class) || ! $this->app->bound(ResourceRegistry::class)) {
            return;
        }

        $this->app->make(NavInvocableRegistry::class)
            ->register($this->app->make(FrameResourcesInvocable::class));
    }
}
