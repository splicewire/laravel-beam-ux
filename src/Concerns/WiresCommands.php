<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Console\CompileEntriesCommand;
use Splicewire\Beam\Ux\Console\EnrichPageSchemasCommand;
use Splicewire\Beam\Ux\Console\PnpmOverridesCommand;
use Splicewire\Beam\Ux\Console\RegisterFromDiskCommand;
use Splicewire\Beam\Ux\Console\ScaffoldCommand;
use Splicewire\Beam\Ux\Console\SeedNavCommand;
use Splicewire\Beam\Ux\Console\UpdateFromNewerCommand;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresCommands
{
    /**
     * Register the two operator-run batch commands (charter S8). Names mirror the package tree (ADR-0167):
     * `splicewire:beam:ux:register-from-disk` + `splicewire:beam:ux:update-from-newer`. Only in console.
     */
    #[Chained('boot', order: 30)]
    protected function bootCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            RegisterFromDiskCommand::class,
            UpdateFromNewerCommand::class,
            ScaffoldCommand::class,
            SeedNavCommand::class,
            CompileEntriesCommand::class,
            EnrichPageSchemasCommand::class,
            PnpmOverridesCommand::class,
        ]);
    }
}
