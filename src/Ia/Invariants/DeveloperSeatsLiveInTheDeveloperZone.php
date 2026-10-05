<?php

namespace Splicewire\Beam\Ux\Ia\Invariants;

use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Ia\NavInvariant;

/**
 * **I2** (IA-8, UX-08): no `developer` seat sits under a `zone: primary` node, and that includes host-authored trees.
 *
 * A seat is `{key}.section`; it is a developer seat when the realm's {@see NavSectionRegistry} declares `key` with
 * `audience: developer`. It holds only when its top-level ancestor is a `zone: meta` node, the Developer zone the
 * projector emits. A host seat that is not a declared `NavSection` cannot be judged here until it becomes one (IA-13).
 *
 * A projected node carries no audience, so the key is the join: a seat sharing its key with a seat declared `developer`
 * in the same realm is judged as one. No seat in the estate shares such a key today (measured at UX-08).
 */
class DeveloperSeatsLiveInTheDeveloperZone implements NavInvariant
{
    public function __construct(protected NavSectionRegistry $sections) {}

    public function id(): string
    {
        return 'I2';
    }

    public function violation(string $realm, array $node, array $ancestors, array $crossings): ?string
    {
        $routeName = (string) ($node['routeName'] ?? '');
        if (! str_ends_with($routeName, '.section') || ! in_array(substr($routeName, 0, -8), $this->developerKeys($realm), true)) {
            return null;
        }

        $top = $ancestors[0] ?? $node;

        return ($top['zone'] ?? null) === 'meta' ? null : 'a developer seat outside the Developer zone';
    }

    /** @return list<string> */
    protected function developerKeys(string $realm): array
    {
        $keys = [];
        foreach ($this->sections->for($realm) as $section) {
            if ($section->audience === NavAudience::Developer) {
                $keys[] = $section->key;
            }
        }

        return $keys;
    }
}
