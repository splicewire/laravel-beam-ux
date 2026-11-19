<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Recording\GitRecordingSink;
use Splicewire\Beam\Ux\Release\Kinds\ConfigOverrideMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\EntryMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseMemberKindRegistry;
use Splicewire\Beam\Ux\Release\Sinks\PublishSinkRegistry;

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
     * The {@see ReleaseMemberKindRegistry} (ADR-0243), seeded with `entry` and `config-override` (config-sections 08).
     * Another package registers its own kind on the same singleton, from its provider's boot. The registry's root enters
     * the shared index through the bake, from its `#[IsRegistry]` declaration.
     */
    #[Chained('register', order: 65)]
    protected function registerReleaseKinds(): void
    {
        // config-sections 08: the config-override kind registers on the same singleton beside `entry`; it consumes
        // laravel-beam's ConfigOverrideStore and records under `config/overrides/` (ADR-0243 d4).
        $this->app->singleton(ReleaseMemberKindRegistry::class, fn () => (new ReleaseMemberKindRegistry)
            ->register(new EntryMemberKind)
            ->register(new ConfigOverrideMemberKind));
    }

    /**
     * The {@see PublishSinkRegistry} (ADR-0243), seeded with `git`, the local recording (ADR-0226). A host replaces it or
     * adds a sink on the same singleton.
     */
    #[Chained('register', order: 66)]
    protected function registerPublishSinks(): void
    {
        $this->app->singleton(PublishSinkRegistry::class, fn () => (new PublishSinkRegistry)->register(new GitRecordingSink));
    }

    /**
     * config-sections 10b (08 defect, root ruling 012b6388): Discard draft drops the config members that pinned the
     * discarded draft. laravel-beam's store fires no event of its own, and laravel-beam never depends on beam-ux, so
     * beam-ux observes the scope MODEL it already consumes. {@see ConfigOverrideMemberKind::dropDiscardedMembers()}
     * recognises a discard (the head moved back to the published version) and acts only then.
     */
    #[Chained('boot', order: 67)]
    protected function dropConfigMembersOnDiscard(): void
    {
        ConfigOverrideScope::updated(fn (ConfigOverrideScope $scope) => ConfigOverrideMemberKind::dropDiscardedMembers($scope));
    }
}
