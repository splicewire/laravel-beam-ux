<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigOverrideOp;

/**
 * One key a config member of a Release changes (config-sections ticket 11): what is published, what publishing leaves,
 * which tier sets it after publishing, and when it applies.
 *
 * A SECRET key never carries a value either side: `published` and `after` are null, and `secretChange` says what the
 * Release does to it (`replaced`, `removed`, or `unchanged` when only its op moved), the way the Config area shows a
 * secret as set or not set and never its value.
 */
#[TypeScript, Title('Release config key change')]
final class ReleaseConfigKeyChangeData extends BeamData
{
    public function __construct(
        /** The key's JSON pointer inside the section. */
        public string $pointer,
        /** The op the Release's version holds for the key; null when the version drops the override (the key inherits). */
        public ?ConfigOverrideOp $op,
        /** The override value published now (the replaced version's), or null: not overridden, an explicit null, or a secret. */
        public mixed $published,
        /** Whether the replaced version overrode the key at all (false: the published value is inherited from a lower tier). */
        public bool $publishedSet,
        /** The value publishing leaves on this tier, or null: inherited, an explicit null, or a secret. */
        public mixed $after,
        /** The tier that sets the key after publishing: the Release's own tier, or `inherited` when the override is dropped. */
        public string $setBy,
        /** When the change applies: the next request (`live`), after workers reload (`reload`), or only on a deploy. */
        public ConfigEffect $applies,
        public bool $secret,
        /** For a secret only: `replaced`, `removed` or `unchanged`. Null for a non-secret key. */
        public ?string $secretChange = null,
    ) {}
}
