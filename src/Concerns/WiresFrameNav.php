<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\DataNav\NavInvocableRegistry;
use Rushing\DataNav\NavRegistry;
use Rushing\Popcorn\Concerns\Chained;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Frame\DeclaredSectionNavigation;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;
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
     * `OnKeyDuplicate::Supersede`, so a host re-registering its own collector over this one is the
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

    /**
     * beam-ux seats its OWN two sections — `ops` and `authoring` — the way any package now can.
     *
     * ## Why both realms, for both seats
     *
     * Which realm these resources live in is the HOST's list, not this package's: `beam-ux-entry`,
     * `beam-ux-mirror-status` and `beam-ux-sitemap-health` sit in `operator` at the flagship
     * (`config/frame.realms`, whose own comment argues them into operator explicitly) and
     * `beam-ux-entry` sits in `tenant` at the beam starter. A package that guessed one realm would be
     * invisible at every host that chose the other. So both are declared, and
     * {@see FrameNavContribution} drops the seat that turns out empty — the pairing is what makes
     * declaring both honest rather than sloppy.
     *
     * ## Ungated, and that is a decision
     *
     * `entitlement: null, permission: null` is written out rather than defaulted, because an omission
     * and a decision must not be spelled the same. These seats carry no gate of their own: the
     * resources under them are `viewAny`-gated individually by the collector, and an empty seat is
     * dropped — so an unauthorized reader sees the section disappear because its contents did, which
     * is the same answer a seat-level gate would give with one fewer place to disagree.
     *
     * Boot order does not matter here, and that is by design rather than by luck: the navigation
     * registered at 56 does not ENUMERATE sections at boot — it projects them per request. A seat
     * declared by any package, at any boot order, in any provider, is picked up on the next request.
     * An earlier draft of this pair read `targetedRealmKeys()` eagerly at 56 and would have silently
     * missed every package whose provider booted later.
     */
    #[Chained('boot', order: 57)]
    protected function bootOwnNavSections(): void
    {
        if (! $this->app->bound(NavSectionRegistry::class)) {
            return;
        }

        $sections = $this->app->make(NavSectionRegistry::class);

        foreach (['operator', 'tenant'] as $realm) {
            $sections->register(
                new NavSection(
                    key: 'authoring', realm: $realm, label: 'Authoring',
                    icon: 'FileText', href: '/authoring', order: 30,
                    entitlement: null, permission: null,
                ),
                by: 'splicewire/laravel-beam-ux',
            );

            $sections->register(
                new NavSection(
                    key: 'ops', realm: $realm, label: 'Ops',
                    icon: 'Server', href: '/ops', order: 80,
                    entitlement: null, permission: null,
                ),
                by: 'splicewire/laravel-beam-ux',
            );
        }
    }

    /**
     * Register a DEFAULT navigation for every realm a package declared a seat for — so a host that
     * installs beam-calendars gets a Calendars section without writing a navigation at all.
     *
     * ## It cannot clobber a host, and not by being careful
     *
     * `NavRegistry` is `PickOne`/`Supersede`, and Laravel boots every vendor provider before the
     * host's own. So a host registering its own navigation for the same realm replaces this one
     * WHOLESALE, for free, by the ordering the framework already guarantees. The override seam is
     * preserved by doing nothing to defend it — which is why this registers unconditionally rather
     * than checking `has()` first. Checking would invert the precedence: first writer would win, and
     * the host would need to know to unregister us.
     *
     * ## Two intersections, both host facts
     *
     * A seat is registered only where the declared realm EXISTS at this host ({@see RealmRegistry}),
     * and only for realms some package actually targeted. A package declaring a seat for a realm this
     * host does not ship is a silent no-op — the same posture `RealmOverlayRegistry` documents for an
     * overlay whose realmKey was never registered. Neither is an error, because neither is a fact the
     * declaring package could have known.
     *
     * Runs at boot order 56, after the collector above: the navigation's seats point at
     * {@see FrameResourcesInvocable::NAME}, so the capability must already be registered.
     */
    #[Chained('boot', order: 56)]
    protected function bootDeclaredSectionNavigations(): void
    {
        if (! $this->app->bound(NavRegistry::class) || ! $this->app->bound(NavSectionRegistry::class)) {
            return;
        }

        $realms = $this->app->make(RealmRegistry::class);
        $navigations = $this->app->make(NavRegistry::class);

        // Driven by the realms that EXIST, not by the sections declared so far. Sections are projected
        // per request, so this cannot depend on which providers have booted yet — a package declaring
        // a seat at any later boot order is picked up on the next request rather than missed for the
        // life of the process. A realm nobody seated projects an empty list, which is the same tree a
        // host with no navigation already gets.
        foreach (array_keys($realms->all()) as $realm) {
            $navigations->register(
                $realm,
                new DeclaredSectionNavigation($this->app->make(NavSectionProjector::class), $realm),
                by: 'splicewire/laravel-beam-ux',
            );
        }
    }
}
