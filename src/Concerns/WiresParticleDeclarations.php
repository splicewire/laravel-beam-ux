<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Data\BeamUxEntryData;
use Splicewire\Beam\Ux\Data\MirrorStatusRowData;
use Splicewire\Beam\Ux\Data\SitemapHealthRowData;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;
use Splicewire\Beam\Ux\Particle\EntryBodyShowOp;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowShowOp;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp;

/**
 * One concern of {@see BeamUxServiceProvider}: this package registers its OWN particle declarations,
 * so no host config has to name a `Splicewire\Beam\Ux\*` class (ADR-0214 §5).
 *
 * ## Why a host was naming them at all
 *
 * `BeamServiceProvider::discoverParticleAttributes()` reads `beam.core.particle.classes` /
 * `.discover_paths` and no-ops when both are empty — and where `discover_paths` IS set estate-wide it
 * points at the **host's own** `app_path('Data')`, which a package class can never be inside. So every
 * beam-ux declaration reached a registry only because a host listed its FQCN, and three host config
 * files carry the confession in a comment: *"BeamUxEntryData lives in the laravel-beam-ux PACKAGE, so
 * discover_paths' `app_path('Data')` scan never finds it — it needs the explicit list."*
 *
 * That is the exact inverse of the invariant ADR-0210 set for this package's own seed manifest
 * (*"consumers register DOWN into beam's manifest; beam-core never learns a consumer's name"*), and of
 * `particle-contribution-seam` 04 §A1's ruling that the contributor declares and the owner names
 * nothing. It was never a new decision — only a settled one that had not been applied to the particle
 * registries.
 *
 * ## The idiom, and why it is order-safe by construction
 *
 * `particle-contribution-seam` ticket 07 ratified the direct `bound()` → `make()` → `register()` form
 * from `packageBooted()`, and killed the `$app->afterResolving(...)` one that two packages were using:
 * beam **binds** both registries in `packageRegistered()` and **resolves** them in its own
 * `packageBooted()`, and Laravel returns a cached singleton from `$this->instances` WITHOUT firing
 * resolving callbacks — so a hook registered by a later provider never fires, silently. It measured 2
 * callbacks / 0 registrations in `splicewire-app`.
 *
 * Laravel runs `register()` on **every** provider before `boot()` on **any**, so `bound()` is already
 * true whatever the provider order. The guard is for a host predating the registries, not for ordering.
 *
 * ## Why `registerClass()` and not hand-built runtime objects
 *
 * {@see AttributedParticleDiscovery::registerClass()} is the same reflection beam's own config-driven
 * discovery runs — it reads the `#[ParticleResource]` / `#[ParticleOp]` attribute and wires the
 * `handle` / `respond` / `afterWrite` static conventions. Hand-constructing `ParticleResource` /
 * `ParticleOperation` here would be a second, drifting reader of attributes that already declare
 * everything, and the declaration sites would stop being the source of truth.
 *
 * Registration is idempotent by key (last wins), so a host that still lists these classes in
 * `beam.core.particle.classes` during the migration window registers the same declaration twice and
 * gets the same result — which is what makes the host config entries safe to delete on their own
 * schedule rather than in lockstep with this package.
 */
trait WiresParticleDeclarations
{
    /**
     * The three resources and four operations this package declares.
     *
     * Ordered `boot: 5` — ahead of every other boot link, so anything later in the chain (route
     * macros, the workflow handover) can already see the registered declarations.
     */
    #[Chained('boot', order: 5)]
    protected function bootParticleDeclarations(): void
    {
        if (! $this->app->bound(ParticleResourceRegistry::class) || ! $this->app->bound(ParticleOperationRegistry::class)) {
            return;
        }

        $discovery = new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );

        foreach (self::PARTICLE_DECLARATIONS as $class) {
            $discovery->registerClass($class);
        }
    }

    /**
     * Every declaration site in this package, in one list.
     *
     * `BeamUxEntryData` is the `beam-ux-entry` resource the four operations hang off;
     * `MirrorStatusRowData` and `SitemapHealthRowData` are read-only projections over the same model.
     *
     * @var list<class-string>
     */
    protected const PARTICLE_DECLARATIONS = [
        BeamUxEntryData::class,
        MirrorStatusRowData::class,
        SitemapHealthRowData::class,
        EntryWorkflowShowOp::class,
        EntryWorkflowTransitionOp::class,
        EntryBodyShowOp::class,
        EntryBodySaveOp::class,
    ];
}
