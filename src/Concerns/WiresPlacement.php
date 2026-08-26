<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Placement\DatePartitionedPlacement;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Placement\PlacementResolver;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresPlacement
{
    /**
     * The {@see PlacementResolver} — the `FilePlacement` selection seam (charter S2, ADR-0165).
     * Seeded with the default (`namespace/type/slug.ext`, extension from `format`) + the
     * date-partitioned strategy, plus any `namespace → strategy` map from config. A host registers
     * further strategies on the same singleton. `namespace` derives DISK only, never the URL (two trees).
     */
    #[Chained('register', order: 50)]
    protected function registerPlacement(): void
    {
        $this->app->singleton(PlacementResolver::class, function () {
            $resolver = (new PlacementResolver)
                ->register(PlacementResolver::DEFAULT, new DefaultPlacement)
                ->register('date-partitioned', new DatePartitionedPlacement(
                    (string) config('beam.ux.placement.date_root', 'articles'),
                ));

            $map = config('beam.ux.placement.namespaces', []);
            if (is_array($map) && $map !== []) {
                $resolver->mapNamespaces($map);
            }

            return $resolver;
        });
    }

    /**
     * Describe `beam.ux.placements` into the shared {@see RegistryIndex} (registry-kernel ticket 38).
     * In boot, in the trait that owns the fill — see {@see WiresCodecs::describeCodecs()}.
     */
    #[Chained('boot', order: 80)]
    protected function describePlacements(): void
    {
        $this->app->make(RegistryIndex::class)->describe(
            $this->app->make(PlacementResolver::class),
            by: self::class,
        );
    }
}
