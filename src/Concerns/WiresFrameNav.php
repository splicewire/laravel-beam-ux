<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\RouteContextPlan;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;

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
 * retired. A host publishes `beam.ux.frame_nav.route_context` — or binds a constructed plan where
 * the entries deserve docblocks — and that is the host spelling it out.
 */
trait WiresFrameNav
{
    #[Chained('register', order: 55)]
    protected function registerFrameNav(): void
    {
        $this->app->bind(RouteContextPlan::class, function (): RouteContextPlan {
            $config = config('beam.ux.frame_nav.route_context');

            return is_array($config) && $config !== []
                ? RouteContextPlan::fromConfig($config)
                : RouteContextPlan::empty();
        });

        $this->app->bind(RouteContextProjector::class);

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
}
