<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 11): an object key whose children mix a plain `host` and a SECRET `password`, so the Release
 * panel's key diff is shown masking a secret leaf inside an object value.
 */
class ReleaseKeysMail extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $password,
    ) {}
}

/**
 * Fixture (config-sections 11): a section for the Release panel's key diff. `workers` applies only after long-lived
 * workers RELOAD; `mail` is an object with a secret leaf; `mailer` shares `mail`'s prefix, so a fallback built from
 * the keys under `/mail/` must not take it.
 */
#[ConfigRoot(root: 'release-keys', title: 'Release Keys', description: 'Keys a Release changes in the panel tests.')]
class ReleaseKeysSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Reload)]
        public string $workers,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ReleaseKeysMail $mail,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?string $mailer = null,
    ) {}
}
