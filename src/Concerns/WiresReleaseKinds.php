<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Release\Kinds\EntryMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseMemberKindRegistry;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresReleaseKinds
{
    /**
     * The {@see ReleaseMemberKindRegistry} (ADR-0243), seeded with `entry`. Another package registers its own kind on
     * the same singleton, from its provider's boot. The registry's root enters the shared index through the bake, from
     * its `#[IsRegistry]` declaration.
     */
    #[Chained('register', order: 65)]
    protected function registerReleaseKinds(): void
    {
        $this->app->singleton(ReleaseMemberKindRegistry::class, fn () => (new ReleaseMemberKindRegistry)->register(new EntryMemberKind));
    }
}
