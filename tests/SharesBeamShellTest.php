<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Mockery;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountData;
use Splicewire\Beam\Accounts\Data\AccountShellData;
use Splicewire\Beam\Accounts\Data\PlanData;
use Splicewire\Beam\Accounts\Data\ProfileData;
use Splicewire\Beam\Brand\BrandData;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Http\Concerns\SharesBeamShell;
use Splicewire\Beam\Ux\Ia\IaInvariants;

/**
 * make-surface 01: the shell's shared props, packaged from the starters' hand-copied `HandleInertiaRequests`.
 *
 * The first test pins the payload's key list, in the order the starters shared it (measured on
 * laravel-beam-starter `b3f01e9`'s served `/` page, whose Inertia `sharedProps` read
 * `errors,name,brand,realms,auth,sidebarOpen,nav,accountNav,accountShell,can,realmManifest,theme`), so a host that
 * swaps its copy for the trait serves the same keys. The rest prove the two override seams a tailored host uses
 * (tower's variant): the projected principal, and dropping a key through an aliased `share()`.
 */
class SharesBeamShellTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), InertiaServiceProvider::class];
    }

    private function request(?Authenticatable $user = null): Request
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        if ($user !== null) {
            Auth::setUser($user);
        }

        return $request;
    }

    private function user(): User
    {
        return (new User)->forceFill(['id' => 7, 'name' => 'Drew', 'email' => 'drew@example.test']);
    }

    /**
     * The account rail's projection and IA check, stubbed: this suite proves the share, not the containment tree
     * ({@see ContainmentTest}) or the invariants ({@see IaInvariantsTest}).
     *
     * @param  list<NavLink>  $items
     */
    private function accountRail(array $items = []): void
    {
        $projector = Mockery::mock(NavProjector::class);
        $projector->shouldReceive('project')->andReturnUsing(fn (string $realm) => NavTree::make($realm === 'account' ? $items : []));
        $this->app->instance(NavProjector::class, $projector);
        $this->app->instance(IaInvariants::class, new class extends IaInvariants
        {
            public function enforce(string $realm, array $nav, array $crossings = [], array $routeContext = []): array
            {
                return $nav;
            }
        });
    }

    public function test_the_account_rail_drops_a_seat_into_a_realm_the_reader_is_not_entitled_to(): void
    {
        config(['beam.core.realm_gates' => ['operator' => ['entitlement' => 'os.operate']]]);
        Gate::define('entitlement:os.operate', fn () => false);
        $this->accountRail([
            NavLink::make(title: 'Dashboard', href: '/dashboard'),
            NavLink::make(title: 'Operator', href: '/operator/users'),
        ]);

        $rail = (new SharesBeamShellHost)->share($this->request($this->user()))['accountNav'];

        $this->assertSame(['/dashboard'], array_map(fn (object $node): string => $node->href, $rail->items));
    }

    public function test_it_shares_the_starters_keys_in_their_order(): void
    {
        $shared = (new SharesBeamShellHost)->share($this->request());

        $this->assertSame(
            ['errors', 'name', 'brand', 'realms', 'auth', 'sidebarOpen', 'nav', 'accountNav', 'accountShell', 'can', 'realmManifest', 'theme', 'localization'],
            array_keys($shared),
        );
        $this->assertInstanceOf(BrandData::class, $shared['brand']);
        $this->assertSame(['user', 'canAuthorUx'], array_keys($shared['auth']));
        $this->assertTrue($shared['sidebarOpen'], 'no sidebar_state cookie reads as open');
        $this->assertInstanceOf(NavTree::class, $shared['nav']);
        $this->assertIsArray($shared['can']);
        $this->assertIsArray($shared['theme']);
    }

    /**
     * localization 16 (ruling 02 §4): `localization.address` is ordinary and `localization.initial` is a once-prop keyed
     * `beam.catalog:{host}:{locale}:{realm}:{hash}`, proven through the installed adapter's own PropsResolver:
     * - the first (SSR) load carries the catalog;
     * - an Inertia visit already holding that key excludes it;
     * - a different key (a changed hash or context) still carries it.
     */
    public function test_the_initial_catalog_is_a_once_prop_keyed_by_host_locale_realm_and_hash(): void
    {
        // A version-1 discovered catalog written by schemastud's own jcs.mjs (the beam 16-a fixture, same bytes).
        config(['beam.localization.client_catalog' => __DIR__.'/Fixtures/Localization/discovered-catalog.json']);
        $this->app->forgetScopedInstances();
        $shared = (new SharesBeamShellHost)->share($this->request());
        $address = $shared['localization']['address'];
        $key = 'beam.catalog:http://localhost:'.$address->locale.'::'.$address->hash;

        $this->assertInstanceOf(\Splicewire\Beam\Localization\Client\Data\ClientCatalogAddressData::class, $address);
        $this->assertSame($key, $shared['localization']['initial']->getKey());

        $resolve = function (array $headers) use ($shared): array {
            $request = Request::create('/');
            foreach ($headers as $name => $value) {
                $request->headers->set($name, $value);
            }

            // Only the localization prop: the proof concerns it, and the other lazy shell props need host bindings.
            return (new \Inertia\PropsResolver($request, 'Page'))->resolve(['localization' => $shared['localization']], []);
        };

        [$first, $meta] = $resolve([]);
        $this->assertSame('Save', $first['localization']['initial']->messages['schemastud-ui::messages.save']->text ?? $first['localization']['initial']['messages']['schemastud-ui::messages.save']['text'] ?? null);
        $this->assertArrayHasKey($key, $meta['onceProps']);

        [$again] = $resolve(['X-Inertia' => 'true', 'X-Inertia-Except-Once-Props' => $key]);
        $this->assertArrayNotHasKey('initial', $again['localization'], 'an unchanged once-key is not resent');
        $this->assertArrayHasKey('address', $again['localization']);

        [$changed] = $resolve(['X-Inertia' => 'true', 'X-Inertia-Except-Once-Props' => 'beam.catalog:http://localhost:en::older-hash']);
        $this->assertArrayHasKey('initial', $changed['localization'], 'a changed hash is a new key, so the catalog is resent');
    }

    public function test_a_guest_gets_no_account_rail_and_no_account_shell(): void
    {
        $this->app->bind(AccountShellProvider::class, fn () => throw new \RuntimeException('a guest must not reach the provider'));

        $shared = (new SharesBeamShellHost)->share($this->request());

        $this->assertNull($shared['auth']['user']);
        $this->assertSame([], $shared['accountNav']->items);
        $this->assertNull($shared['accountShell']);
    }

    public function test_a_signed_in_user_gets_the_host_bound_account_shell(): void
    {
        $this->accountRail();
        $this->app->bind(AccountShellProvider::class, fn () => new class implements AccountShellProvider
        {
            public function shellFor(?Authenticatable $user): ?AccountShellData
            {
                return new AccountShellData(
                    plan: new PlanData(tier: 'free', label: 'Free'),
                    profile: new ProfileData(handle: '@drew', avatar: 'D', metrics: []),
                    account: new AccountData(email: 'drew@example.test', paymentMethodLabel: null),
                );
            }
        });

        $shared = (new SharesBeamShellHost)->share($this->request($this->user()));

        $this->assertSame('@drew', $shared['accountShell']->profile->handle);
        $this->assertSame(7, $shared['auth']['user']->getKey());
    }

    public function test_a_host_projects_can_and_the_realm_manifest_for_its_own_principal(): void
    {
        $this->accountRail();
        $scope = new \stdClass;
        $projector = Mockery::mock(RealmManifestProjector::class);
        $projector->shouldReceive('project')->with($scope)->andReturn([['key' => 'scoped']]);
        $this->app->instance(RealmManifestProjector::class, $projector);

        $host = new class($scope) extends Middleware
        {
            use SharesBeamShell;

            public function __construct(private object $scope) {}

            protected function shellPrincipal(Request $request): mixed
            {
                return $this->scope;
            }
        };

        $this->assertSame([['key' => 'scoped']], $host->share($this->request($this->user()))['realmManifest']);
    }

    public function test_a_host_drops_a_key_through_an_aliased_share(): void
    {
        $host = new class extends Middleware
        {
            use SharesBeamShell {
                share as beamShellShare;
            }

            public function share(Request $request): array
            {
                return Arr::except($this->beamShellShare($request), ['accountNav']);
            }
        };

        $shared = $host->share($this->request());

        $this->assertArrayNotHasKey('accountNav', $shared);
        $this->assertArrayHasKey('accountShell', $shared);
        $this->assertArrayHasKey('realmManifest', $shared);
    }
}

/** A starter's thin `HandleInertiaRequests`: the trait and nothing else. */
class SharesBeamShellHost extends Middleware
{
    use SharesBeamShell;
}
