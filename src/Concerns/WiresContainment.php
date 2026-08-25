<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresContainment
{
    /**
     * The **containment** seam (charter S3, ADR-0165 — the "two trees": containment → URL/nav). Binds the
     * {@see UrlResolver} (composes `segment` DOWN the realm-rooted tree into the public URL,
     * decoupled from `namespace`) and the {@see NavProjector} (projects a realm's tree into a
     * composed-down `rushing/laravel-data-nav` `NavTree`). Both singletons — the resolver is stateless; the
     * `BeamUxEntry::url()` accessor resolves the bound instance. Multiplicity IS built (ticket 03): an
     * entry's `realms` fallback stack means it can be reachable in several realms at once.
     */
    #[Chained('register', order: 80)]
    protected function registerContainment(): void
    {
        $this->app->singleton(UrlResolver::class, fn () => new UrlResolver);
        $this->app->singleton(NavProjector::class, fn ($app) => new NavProjector(
            $app->make(UrlResolver::class),
            $app->make(EntryAccessResolver::class),
            $app->make(EntryPublishGate::class),
        ));
    }
}
