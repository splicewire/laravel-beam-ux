<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;

/**
 * A test-only, directly-controllable stand-in for beam-accounts' `DefaultEntitlementResolver` — the
 * grant-cascade RESOLUTION itself is exercised end-to-end in beam-accounts' own suite; this repo only
 * needs to prove `EntryPromoter` correctly CONSULTS whatever the bound resolver says.
 */
class FakeEntitlementResolver implements EntitlementResolver
{
    /** @var list<string> */
    public array $keys = [];

    /**
     * @return list<string>
     */
    public function entitlementsFor(mixed $principal): array
    {
        return $this->keys;
    }
}
