<?php

namespace Splicewire\Beam\Ux\Ia;

use RuntimeException;

/**
 * A host-authored nav tree broke an IA invariant. Thrown at the host, while it is being built, so the author of the
 * offending row sees it (IA-12). Package-contributed trees are pruned instead and never throw this.
 */
class IaInvariantViolation extends RuntimeException
{
    /** @param  array<string, string>  $violations  violation id => why */
    public function __construct(public readonly string $realm, public readonly array $violations)
    {
        $lines = [];
        foreach ($violations as $id => $why) {
            $lines[] = "{$id} ({$why})";
        }

        parent::__construct("The {$realm} rail breaks the IA invariants: ".implode('; ', $lines));
    }
}
