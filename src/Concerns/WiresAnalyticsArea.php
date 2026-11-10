<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\Analytics\SiteArea;
use Splicewire\Beam\Ux\BeamUxServiceProvider;

/**
 * One concern of {@see BeamUxServiceProvider}: beam-ux's contribution to the Analytics section, the **Site** area
 * (analytics-area ticket 10).
 *
 * It is contributed the way laravel-beam-analytics asks every package to contribute: an `afterResolving` on the area
 * registry, named as a string. beam-ux already requires laravel-beam-analytics (it renders the tag on every site), so the
 * area class implements its contract directly; the string keeps the contribution in the registry's own documented shape.
 *
 * Registered in the `register` chain, because laravel-beam-analytics resolves the registry while it boots, which would
 * miss a callback added from this package's boot.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresAnalyticsArea
{
    #[Chained('register', order: 120)]
    protected function registerAnalyticsArea(): void
    {
        $this->app->afterResolving('Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry', fn ($areas) => $areas
            ->add(SiteArea::class, 'splicewire/laravel-beam-ux'));
    }
}
