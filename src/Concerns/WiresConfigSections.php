<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Config\BeamUxSection;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresConfigSections
{
    /**
     * The `beam.ux` Config Section (laravel-beam ADR-0245; config-sections ticket 05), registered on laravel-beam's
     * {@see ConfigSectionRegistry} as that package registers its own: after the registry resolves, with the package file
     * that holds the package tier.
     */
    #[Chained('register', order: 67)]
    protected function registerConfigSections(): void
    {
        $this->callAfterResolving(ConfigSectionRegistry::class, static function (ConfigSectionRegistry $sections): void {
            $sections->section(
                BeamUxSection::class,
                owner: 'splicewire/laravel-beam-ux',
                packageFile: dirname(__DIR__, 2).'/config/beam/ux.php',
            );
        });
    }
}
