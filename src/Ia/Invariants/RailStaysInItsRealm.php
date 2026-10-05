<?php

namespace Splicewire\Beam\Ux\Ia\Invariants;

use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Ia\IaInvariants;
use Splicewire\Beam\Ux\Ia\NavInvariant;

/**
 * **I1** (IA-3): no node in realm R's rail has an href under another registered realm's `routeBase`. Realms are crossed
 * only through the realm switcher.
 *
 * An href belongs to the realms whose `routeBase` is its longest prefix, so `/operator/tenants` is the operator's
 * even though the tenant's `/` also contains it, and a path under `/` alone belongs to both `tenant` and `site`, which
 * share that base. The node holds when R is one of them.
 */
class RailStaysInItsRealm implements NavInvariant
{
    public function __construct(protected RealmRegistry $realms) {}

    public function id(): string
    {
        return 'I1';
    }

    public function violation(string $realm, array $node, array $ancestors, array $crossings): ?string
    {
        $path = IaInvariants::internalPath($node['href'] ?? null);
        if ($path === null || IaInvariants::isGroupHeader($node)) {
            return null;
        }

        $owners = $this->owners($path);
        if ($owners === [] || in_array($realm, $owners, true) || array_intersect($owners, $crossings) !== []) {
            return null;
        }

        return 'points into the '.implode('/', $owners).' realm';
    }

    /** @return list<string> the realms whose routeBase is the longest prefix of the path */
    protected function owners(string $path): array
    {
        $owners = [];
        $longest = -1;

        foreach ($this->realms->all() as $key => $definition) {
            $base = '/'.trim($definition->routeBase, '/');
            $contains = $base === '/' || $path === $base || str_starts_with($path, $base.'/');
            if (! $contains) {
                continue;
            }

            $length = strlen(rtrim($base, '/'));
            if ($length > $longest) {
                [$owners, $longest] = [[$key], $length];
            } elseif ($length === $longest) {
                $owners[] = $key;
            }
        }

        return $owners;
    }
}
