<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Sitemap\SitemapSourceRegistry;
use Splicewire\Beam\Ux\Sitemap\EntryEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;
use Splicewire\Beam\Ux\Sitemap\PublicEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\WorkflowMarkingPublishGate;

/**
 * The sitemap source: its container binding, and the boot-time registration that hands it to
 * beam-sitemap.
 *
 * ⚠️ Contributes to BOTH chains — `register` for the binding, `boot` for the handover — one concern,
 * two links, which is the case the `boot{TraitBasename}` convention cannot express.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresSitemap
{
    /**
     * The **sitemap** seam (charter S5, ADR-0166). beam-ux is the arm's first-class
     * consumer: it binds the two gate ports the {@see EntrySitemapSource} composes,
     * then registers that source onto the sibling `laravel-beam-sitemap`
     * registry at boot. The arm owns the plumbing (contract, registry, controller,
     * command, RouteSitemapSource); beam-ux owns the ENTRY data source.
     *
     * The two gate ports:
     *  - {@see EntryPublishGate} → {@see WorkflowMarkingPublishGate} (S6, real): reads
     *    the entry's persisted `workflow_marking`. An unmanaged entry (no binding) is
     *    published; a managed entry is public only at the published marking. This
     *    replaces the S4/S5 `AlwaysPublishedGate` stub, which is now deleted. Still
     *    re-bindable by a host.
     *  - {@see EntryEntitlementGate} → {@see PublicEntitlementGate} (every entry
     *    public; a gating host re-binds to consult its own entitlement authority).
     */
    #[Chained('register', order: 90)]
    protected function registerSitemap(): void
    {
        $this->app->bind(EntryPublishGate::class, WorkflowMarkingPublishGate::class);
        $this->app->bind(EntryEntitlementGate::class, PublicEntitlementGate::class);
    }

    /**
     * Register {@see EntrySitemapSource} onto the arm's {@see SitemapSourceRegistry}.
     * Guarded on the registry existing (the arm being installed) so beam-ux still
     * boots standalone without the sitemap arm. Gated by
     * `beam.ux.sitemap.enabled` (default on).
     */
    #[Chained('boot', order: 10)]
    protected function bootSitemap(): void
    {
        if (! config('beam.ux.sitemap.enabled', true)) {
            return;
        }

        if (! class_exists(SitemapSourceRegistry::class)) {
            return;
        }

        $this->app->make(SitemapSourceRegistry::class)
            ->register(EntrySitemapSource::class);
    }
}
