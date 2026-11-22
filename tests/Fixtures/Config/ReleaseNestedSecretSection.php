<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 11 r5): the `release-nested` section as it was FIRST declared, with the secret leaves still
 * PLAIN, so a version saved under it stores plaintext. {@see ReleaseNestedSecretSection} re-declares the same root with
 * `relay.auth.token` (two levels down) and `deep.outer.inner.token` (three levels down) secret. Secret values are FAKE.
 *
 * The omittable siblings (`user`, `pub`, `port`) are `Optional`, so an object set that names only some keys validates
 * against the section schema (a non-`Optional` nullable property must be present in an object set, like `token`/`key`).
 *
 * The file is named for {@see ReleaseNestedSecretSection}: loading that class (the one the tests reference) loads this
 * file and so defines every class below it.
 */
#[ConfigRoot(root: 'release-nested', title: 'Release Nested', description: 'Nested secrets, two and three levels down.')]
class ReleaseNestedPlainSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedPlainRelay $relay = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedPlainDeep $deep = null,
        // `smtp` is a PLAIN object here and a SECRET object in ReleaseNestedSecretSection: the secret-to-plain transition
        // the r7 row-demotion test needs (a stored-secret /smtp marker with a plain /smtp/password child row).
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedSmtp $smtp = null,
    ) {}
}

class ReleaseNestedPlainRelay extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedPlainAuth $auth = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string|Optional|null $region = null,
    ) {}
}

class ReleaseNestedPlainAuth extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?string $token = null,
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string|Optional|null $user = null,
    ) {}
}

class ReleaseNestedPlainDeep extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedPlainOuter $outer = null,
    ) {}
}

class ReleaseNestedPlainOuter extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedPlainInner $inner = null,
    ) {}
}

class ReleaseNestedPlainInner extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?string $token = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string|Optional|null $pub = null,
    ) {}
}

/**
 * The same root re-declared: `relay.auth.token` and `deep.outer.inner.token` are now secret (a leaf two and three
 * levels below the parent an operator sets), `relay.auth.user` stays a HOST-ceiling sibling (import must leave it
 * alone), `smtp` is a SECRET object whose children carry their OWN `#[ConfigKey]` (the ancestor rule, not the nearest
 * attribute, decides they are secret — config-sections 11 r5 MEDIUM), and `vault` is a parent whose ONLY child is
 * secret (its mirror value is the object `{}`).
 */
#[ConfigRoot(root: 'release-nested', title: 'Release Nested', description: 'Nested secrets, two and three levels down.')]
class ReleaseNestedSecretSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedSecretRelay $relay = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedSecretDeep $deep = null,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?ReleaseNestedSmtp $smtp = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedVault $vault = null,
    ) {}
}

class ReleaseNestedSecretRelay extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedSecretAuth $auth = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string|Optional|null $region = null,
    ) {}
}

class ReleaseNestedSecretAuth extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $token = null,
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string|Optional|null $user = null,
    ) {}
}

class ReleaseNestedSecretDeep extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedSecretOuter $outer = null,
    ) {}
}

class ReleaseNestedSecretOuter extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public ?ReleaseNestedSecretInner $inner = null,
    ) {}
}

class ReleaseNestedSecretInner extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $token = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string|Optional|null $pub = null,
    ) {}
}

class ReleaseNestedSmtp extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string|Optional|null $password = null,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string|Optional|null $port = null,
    ) {}
}

class ReleaseNestedVault extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $key = null,
    ) {}
}
