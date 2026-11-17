<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 08): a section a Release carries. `heading` is a plain operator-tier string that appears in
 * the mirror; `token` is a SECRET that must never appear in `config/overrides/release-fixture.json` or the commit.
 */
#[ConfigRoot(root: 'release-fixture', title: 'Release Fixture', description: 'A section carried by a Release in tests.')]
class ReleaseConfigSection extends ConfigSection
{
    public function __construct(
        #[Title('Heading'), Description('An operator-tier string; appears in the mirror.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public string $heading,
        #[Title('Token'), Description('A secret; never mirrored or committed.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live, secret: true)]
        public string|Optional|null $token,
        #[Title('Banner'), Description('A TENANT-admissible string (ceiling Tenant): a tenant Release publishes it on the tenant tier.')]
        #[ConfigKey(ceiling: ConfigTier::Tenant, effect: ConfigEffect::Live)]
        public string|Optional|null $banner = null,
    ) {}
}
