<?php

namespace Splicewire\Beam\Ux\Entitlements;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Accounts\Entitlements\Contracts\RealmGrantable;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * beam-ux's OOTB binding of beam-accounts' `RealmGrantable` port (ACC-01): `BeamUxEntry` IS the
 * realm root — {@see BeamUxEntry::rootFor()} already identifies one structurally per realm (the
 * reserved `realms` build namespace + the realm as `slug`). Bound in
 * `Splicewire\Beam\Ux\BeamUxServiceProvider::registerEntitlements()` unless a host already
 * configured `beam.accounts.entitlements.realm_grantable` itself.
 */
class BeamUxRealmGrantable implements RealmGrantable
{
    public function grantableMorphType(): string
    {
        return (new BeamUxEntry)->getMorphClass();
    }

    public function realmForGrantableId(string $id): ?string
    {
        return BeamUxEntry::query()->whereKey($id)->value('realm');
    }

    /** @return list<string> */
    public function provisionedRealms(): array
    {
        return BeamUxEntry::query()->where('namespace', 'realms')->pluck('slug')->all();
    }

    public function rootFor(string $realm): Model
    {
        return BeamUxEntry::rootFor($realm);
    }
}
