<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Access\TokenAccessGate;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Containment\NavProjector;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresAccess
{
    /**
     * The **access** seam (ADR-0212) — the actor-aware third gate that supplies the semantics ADR-0209
     * §5 deferred. It joins the two sitemap gates rather than replacing either: those take no actor and
     * answer *anonymous crawlability*, this one takes an actor and answers *this reader's
     * authorization*, so no re-binding can make one do the other's job.
     *
     * The default binding is {@see TokenAccessGate} — `Splicewire\Tower\Navigation\Gates\AccessGate`
     * descended (ADR-0212 §6), with its two host-specific values (the root role name, and any host
     * tokens beyond ADR-0118's `alias.verb` shape) now config rather than hardcoded. It degrades
     * headless: with no RBAC package present `root` and permission tokens simply deny instead of
     * fataling, so beam-ux stays installable on its own.
     *
     * {@see EntryAccessResolver} is the conjunctive ancestor walk over that gate, bound separately so
     * the renderer and {@see NavProjector} share ONE inheritance rule — a host re-binding the gate
     * never has to reimplement composition.
     */
    #[Chained('register', order: 70)]
    protected function registerAccess(): void
    {
        $this->app->singleton(EntryAccessGate::class, fn () => new TokenAccessGate(
            (string) config('beam.ux.access.root_role', TokenAccessGate::DEFAULT_ROOT_ROLE),
            (array) config('beam.ux.access.extra_tokens', []),
        ));

        $this->app->singleton(
            EntryAccessResolver::class,
            fn ($app) => new EntryAccessResolver($app->make(EntryAccessGate::class)),
        );
    }
}
