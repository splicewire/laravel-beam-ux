<?php

namespace Splicewire\Beam\Ux\Type;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a Release does to a SECRET config key (config-sections ticket 11). The panel shows this word and never a value.
 * Generated to TypeScript, so the wire type is this union and not a bare string.
 */
#[TypeScript]
enum ReleaseConfigSecretChange: string implements ProvidesEnumLabel
{
    /** Set to a new secret value (including a secret set where none was before). */
    case Replaced = 'replaced';

    /** The override is dropped, or set to an explicit null: the secret value goes away. */
    case Removed = 'removed';

    /** The same secret: listed only when something else about the key moved. */
    case Unchanged = 'unchanged';

    /** The case's label: the word the panel shows (the identifier-label convention; as UxType). */
    public function label(): string
    {
        return $this->value;
    }
}
