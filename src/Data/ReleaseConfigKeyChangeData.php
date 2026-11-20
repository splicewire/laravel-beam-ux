<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Ux\Release\ReleaseConfigSecretChange;

/**
 * One key a config member of a Release changes (config-sections ticket 11): what is published, what publishing leaves,
 * which tier sets it after publishing, and when it applies.
 *
 * A SECRET key never carries a value either side: `published` and `after` are null, and `secretChange` says what the
 * Release does to it ({@see ReleaseConfigSecretChange}), the way the Config area shows a secret as set or not set and
 * never its value.
 */
#[TypeScript, Title('Release config key change')]
final class ReleaseConfigKeyChangeData extends BeamData
{
    public function __construct(
        /** The key's JSON pointer inside the section. */
        public string $pointer,
        /** The op the Release's version holds for the key; null when the version drops the override (the key inherits). */
        public ?ConfigOverrideOp $op,
        /**
         * The key's EFFECTIVE value now, through the whole ladder (package, host, provider, operator, tenant), as the
         * Config area shows it. On a published Release that is the value now in force. Null for a secret; a secret leaf
         * inside an object is masked to null.
         */
        public mixed $published,
        /** Whether the replaced version overrode the key on the Release's tier (false: `published` comes from a lower tier). */
        public bool $publishedSet,
        /**
         * The effective value after publishing: the new value for a `set`, null for an explicit null, and for a dropped
         * override the value it falls back to from the tier below. Secrets masked as for `published`.
         */
        public mixed $after,
        /** The tier that sets the key after publishing (a ConfigTier value): the Release's tier, or the fallback tier. */
        public ConfigTier $setBy,
        /** When the change applies: the next request (`live`), after workers reload (`reload`), or only on a deploy. */
        public ConfigEffect $applies,
        public bool $secret,
        /** For a secret only: what the Release does to it. Null for a non-secret key. */
        public ?ReleaseConfigSecretChange $secretChange = null,
    ) {}
}
