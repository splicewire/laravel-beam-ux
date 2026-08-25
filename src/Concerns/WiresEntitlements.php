<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Entitlements\BeamUxRealmGrantable;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresEntitlements
{
    /**
     * ACC-01: bind beam-ux's OOTB `RealmGrantable` implementation
     * ({@see BeamUxRealmGrantable} — `BeamUxEntry` IS the realm root) onto beam-accounts'
     * `config('beam.accounts.entitlements.realm_grantable')` port, UNLESS the host already set that
     * key itself — mirroring the same conditional-bind idiom `BeamAccountsServiceProvider` uses for
     * `permission-cascade.entitlement_resolver`. This is what turns `DefaultEntitlementResolver`'s
     * grant cascade on for a fresh beam-ux install with no host edit required.
     */
    #[Chained('register', order: 110)]
    protected function registerEntitlements(): void
    {
        if (config('beam.accounts.entitlements.realm_grantable') === null) {
            config(['beam.accounts.entitlements.realm_grantable' => BeamUxRealmGrantable::class]);
        }
    }
}
