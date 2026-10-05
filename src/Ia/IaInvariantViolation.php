<?php

namespace Splicewire\Beam\Ux\Ia;

use RuntimeException;

/**
 * A host-authored nav tree broke an IA invariant. Thrown at the host, while it is being built, so the author of the
 * offending row sees it (IA-12). In production it is REPORTED instead, at error level, and the breaking nodes are pruned
 * ({@see IaInvariants::enforce()}). Package-contributed trees are pruned and never raise this.
 */
class IaInvariantViolation extends RuntimeException
{
    /** @param  array<string, string>  $violations  violation id => why */
    public function __construct(public readonly string $realm, public readonly array $violations, public readonly string $host = '')
    {
        $lines = [];
        foreach ($violations as $id => $why) {
            $lines[] = "{$id} ({$why})";
        }

        $at = $host === '' ? '' : " at {$host}";

        parent::__construct("The {$realm} rail{$at} breaks the IA invariants: ".implode('; ', $lines));
    }
}
