<?php

namespace Splicewire\Beam\Ux\Config;

use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * The `beam.ux` Config Section (laravel-beam ADR-0245; config-sections ticket 05). It declares the `releases` key, whose
 * `review_required` an operator may override live, so "publish" and "submit for review" switch with no deploy.
 *
 * Every other key of `beam.ux` stays undeclared, which means deploy-only and not overridable (ADR-0245
 * §Consequences); ticket 13 declares the rest of the root.
 */
#[ConfigRoot(root: 'beam.ux', title: 'Site builder', description: 'How the site builder publishes pages, themes and Releases.')]
class BeamUxSection extends ConfigSection
{
    public function __construct(
        public BeamUxReleasesConfig $releases,
    ) {}
}
