<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 11, build-qa HIGH): the `release-later` section as it was FIRST declared, with `apiKey` and
 * the object `relay` (and its `token` leaf) NOT secret. A version saved under it stores plaintext with `secret: false`.
 * {@see ReleaseLaterSecretSection} is the same root re-declared with those keys secret.
 */
#[ConfigRoot(root: 'release-later', title: 'Release Later', description: 'A section whose keys become secret later.')]
class ReleaseLaterPlainSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?string $apiKey = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseLaterPlainRelay $relay = null,
    ) {}
}

class ReleaseLaterPlainRelay extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?string $host = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?string $token = null,
    ) {}
}

/** The same root re-declared: `apiKey` is now secret, and so is `relay.token`, a secret leaf inside an object. */
#[ConfigRoot(root: 'release-later', title: 'Release Later', description: 'A section whose keys become secret later.')]
class ReleaseLaterSecretSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $apiKey = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseLaterSecretRelay $relay = null,
    ) {}
}

class ReleaseLaterSecretRelay extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?string $host = null,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $token = null,
    ) {}
}
