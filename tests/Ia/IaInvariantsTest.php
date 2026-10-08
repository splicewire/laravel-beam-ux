<?php

namespace Splicewire\Beam\Ux\Tests\Ia;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavRegistry;
use Rushing\DataNav\NavTree;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Frame\DeclaredSectionNavigation;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;
use Splicewire\Beam\Ux\Ia\IaCheckedNavContributor;
use Splicewire\Beam\Ux\Ia\IaInvariants;
use Splicewire\Beam\Ux\Ia\IaInvariantViolation;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * M5 (ux-walkthrough SPEC, UX-06): the IA invariants run on the PROJECTED nav, through a decorator over whatever
 * {@see FrameNavContributor} the host binds. A host-authored tree that breaks one throws, at the host, while it is being
 * built; a package-contributed tree is pruned, because the host only installed the package. I2 and I3 plug into the
 * same slot with UX-08 and UX-09, which own the fields they read.
 *
 * The kit's realms: `operator` at `/operator`, `user` at `/settings`, `tenant` and `site` at `/`.
 */
class IaInvariantsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::get('dashboard', fn () => 'dash')->name('dashboard')->defaults(SeatGate::OPEN_TO_MEMBERS, true);
        Route::get('operator/tenants', fn () => 'tenants')->name('operator.tenants')->defaults(SeatGate::OPEN_TO_MEMBERS, true);
        Route::get('settings/profile', fn () => 'profile')->name('settings.profile')->defaults(SeatGate::OPEN_TO_MEMBERS, true);
        Route::get('projects/{project}', fn () => 'project')->name('projects.show')->defaults(SeatGate::OPEN_TO_MEMBERS, true);
        // The public site's entry route, mounted last as a host mounts it: it answers every other path.
        Route::get('{path}', fn () => 'site entry')->where('path', '.*')->name('site.entry')
            ->defaults('beamUxRealm', 'site')->defaults(SeatGate::OPEN_TO_MEMBERS, true);
    }

    /** A host's own navigation for the realm: a host-authored tree. */
    private function hostNav(string $realm, array $links): void
    {
        $this->app->make(NavRegistry::class)->register($realm, fn (): array => $links, by: 'host');
    }

    /** The realm's navigation is the package's declared one: the tree a contributor hands back is package-contributed. */
    private function packageNav(string $realm): void
    {
        $this->app->make(NavRegistry::class)->register(
            $realm,
            new DeclaredSectionNavigation($this->app->make(NavSectionProjector::class), $realm),
            by: 'pkg',
        );
    }

    /**
     * A package seat in the realm carrying hand-authored rows, built by the package's own contributor.
     *
     * @param  list<array{title: string, href: string}>  $static
     */
    private function packageSeat(string $realm, string $href, array $static): void
    {
        $this->packageNav($realm);
        $this->app->make(NavSectionRegistry::class)->register(
            new NavSection(
                key: 'platform', realm: $realm, label: 'Platform', icon: 'Server', href: $href, order: 10,
                entitlement: null, permission: null, audience: NavAudience::Product, static: $static,
            ),
            by: 'pkg',
        );
    }

    /** A host contributor that hands back exactly this nav, whatever builder made it. */
    private function bindContributor(array $items): void
    {
        $this->app->bind(FrameNavContributor::class, fn () => new class($items) implements FrameNavContributor
        {
            public function __construct(private array $items) {}

            public function contributeNav(?string $realm): ?array
            {
                return ['nav' => NavTree::make($this->items)->toArray(), 'routeContext' => []];
            }
        });
    }

    /** @return list<string> every href in a contributed nav block, depth-first */
    private function declareOperatorSection(string $key): void
    {
        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: $key, realm: 'operator', label: ucfirst($key), icon: 'Square', href: '/'.$key, order: 10,
            entitlement: null, permission: null, audience: NavAudience::Product,
        ), by: 'test');
    }

    private function hrefs(?array $block): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                $out[] = $node['href'] ?? null;
                $walk($node['children'] ?? []);
            }
        };
        $walk($block['nav']['items'] ?? []);

        return $out;
    }

    public function test_the_resolved_port_is_the_checked_decorator_over_the_packages_contributor(): void
    {
        $port = $this->app->make(FrameNavContributor::class);

        $this->assertInstanceOf(IaCheckedNavContributor::class, $port);
        $this->assertInstanceOf(FrameNavContribution::class, $port->inner());
    }

    public function test_i1_a_host_rail_node_crossing_into_another_realm_throws(): void
    {
        $this->hostNav('tenant', [
            NavLink::make(title: 'Dashboard', href: '/dashboard'),
            NavLink::make(title: 'Operator', href: '/operator/tenants'),
        ]);

        $this->expectException(IaInvariantViolation::class);
        $this->expectExceptionMessageMatches('#I1 tenant /operator/tenants#');

        $this->app->make(FrameNavContributor::class)->contributeNav('tenant');
    }

    public function test_in_production_a_host_breach_is_reported_and_pruned_not_thrown(): void
    {
        // Production's default (`beam.ux.ia.throw` is off there): route mounting differs by environment, so a link
        // valid where the host was built may be unmounted here, and that must not take down every page.
        config(['beam.ux.ia.throw' => false]);
        Log::spy();
        $this->hostNav('tenant', [
            NavLink::make(title: 'Dashboard', href: '/dashboard'),
            NavLink::make(title: 'Operator', href: '/operator/tenants'),
        ]);

        $block = $this->app->make(FrameNavContributor::class)->contributeNav('tenant');

        $this->assertSame(['/dashboard'], $this->hrefs($block));
        // Loud, not silent: an error-level line naming the host, the realm and the pruned node.
        Log::shouldHaveReceived('error')->with(Mockery::on(
            fn (string $message): bool => str_contains($message, 'tenant rail at localhost') && str_contains($message, 'I1 tenant /operator/tenants'),
        ), Mockery::any())->once();
    }

    public function test_the_invariants_throw_by_default_everywhere_but_production(): void
    {
        $invariants = $this->app->make(IaInvariants::class);
        $this->assertTrue($invariants->throws());

        $this->app['env'] = 'production';
        $this->assertFalse($invariants->throws());
    }

    public function test_i1_a_package_rail_node_crossing_into_another_realm_is_pruned(): void
    {
        $this->packageSeat('tenant', '/dashboard', [
            ['title' => 'Dashboard', 'href' => '/dashboard'],
            ['title' => 'Profile', 'href' => '/settings/profile'],
        ]);

        $hrefs = $this->hrefs($this->app->make(FrameNavContributor::class)->contributeNav('tenant'));

        $this->assertContains('/dashboard', $hrefs);
        $this->assertNotContains('/settings/profile', $hrefs);
    }

    public function test_i1_an_href_inside_the_rails_own_realm_passes_including_the_longest_base(): void
    {
        // In a declared task section, which an operator row needs (I3).
        $this->declareOperatorSection('tenants');
        $this->hostNav('operator', [
            NavLink::make(title: 'Tenants', routeName: 'tenants.section')->stamped(active: false, activeTrail: false, children: [
                NavLink::make(title: 'Tenants', href: '/operator/tenants'),
            ]),
        ]);

        $block = $this->app->make(FrameNavContributor::class)->contributeNav('operator');

        $this->assertSame(['/operator/tenants'], array_values(array_filter($this->hrefs($block))));
    }

    public function test_i4_a_host_href_that_joins_no_mounted_get_route_throws(): void
    {
        $this->hostNav('tenant', [NavLink::make(title: 'Gone', href: '/reports')]);

        $this->expectException(IaInvariantViolation::class);
        $this->expectExceptionMessageMatches('#I4 tenant /reports#');

        $this->app->make(FrameNavContributor::class)->contributeNav('tenant');
    }

    public function test_i4_holds_for_a_node_without_a_route_name_and_joins_a_parameterised_route(): void
    {
        $this->hostNav('tenant', [NavLink::make(title: 'Project', href: '/projects/42?tab=files#top')]);

        $block = $this->app->make(FrameNavContributor::class)->contributeNav('tenant');

        $this->assertSame(['/projects/42?tab=files#top'], $this->hrefs($block));
    }

    public function test_i4_a_package_row_that_joins_no_mounted_route_is_pruned(): void
    {
        $this->packageSeat('tenant', '/dashboard', [
            ['title' => 'Dashboard', 'href' => '/dashboard'],
            ['title' => 'Reports', 'href' => '/reports'],
        ]);

        $hrefs = $this->hrefs($this->app->make(FrameNavContributor::class)->contributeNav('tenant'));

        $this->assertContains('/dashboard', $hrefs);
        $this->assertNotContains('/reports', $hrefs);
    }

    public function test_pruning_drops_a_breaking_node_with_its_subtree_and_keeps_the_shape(): void
    {
        $nav = NavTree::make([
            NavLink::make(title: 'Dashboard', href: '/dashboard'),
            NavLink::make(title: 'Reports', href: '/reports')->stamped(active: false, activeTrail: false, children: [
                NavLink::make(title: 'Inner', href: '/dashboard'),
            ]),
        ])->toArray();

        $pruned = $this->app->make(IaInvariants::class)->prune('tenant', $nav);

        $this->assertSame(['/dashboard'], $this->hrefs(['nav' => $pruned]));
        $this->assertSame(array_keys($nav), array_keys($pruned));
    }

    public function test_i4_a_rail_href_only_the_sites_entry_route_answers_joins_nothing_in_another_realm(): void
    {
        $nav = NavTree::make([NavLink::make(title: 'About', href: '/about')])->toArray();
        $invariants = $this->app->make(IaInvariants::class);

        $this->assertSame(['I4 tenant /about'], array_keys($invariants->violations('tenant', $nav)));
        $this->assertSame([], $invariants->violations('site', $nav), 'the site rail is what that route serves');
    }

    public function test_an_external_link_is_judged_by_neither_invariant(): void
    {
        $this->hostNav('tenant', [NavLink::make(title: 'Docs', href: 'https://docs.example.com/operator/x')]);

        $block = $this->app->make(FrameNavContributor::class)->contributeNav('tenant');

        $this->assertSame(['https://docs.example.com/operator/x'], $this->hrefs($block));
    }

    public function test_a_decorated_custom_contributor_is_still_checked(): void
    {
        // Even beside the package's declared navigation: the host's contributor wrote this tree.
        $this->packageNav('tenant');
        $this->bindContributor([NavLink::make(title: 'Operator', href: '/operator/tenants')]);

        $port = $this->app->make(FrameNavContributor::class);
        $this->assertInstanceOf(IaCheckedNavContributor::class, $port);

        $this->expectException(IaInvariantViolation::class);
        $this->expectExceptionMessageMatches('#I1 tenant /operator/tenants#');

        $port->contributeNav('tenant');
    }

    public function test_a_declined_realm_passes_through_as_null(): void
    {
        $this->assertNull($this->app->make(FrameNavContributor::class)->contributeNav('no-such-realm'));
    }

    public function test_an_allowed_crossing_passes_and_only_into_the_named_realms(): void
    {
        $invariants = $this->app->make(IaInvariants::class);
        $nav = NavTree::make([
            NavLink::make(title: 'Operator', href: '/operator/tenants'),
            NavLink::make(title: 'Profile', href: '/settings/profile'),
        ])->toArray();

        $this->assertSame([], $invariants->violations('tenant', $nav, crossings: ['operator', 'user']));
        $this->assertSame(['I1 tenant /settings/profile'], array_keys($invariants->violations('tenant', $nav, crossings: ['operator'])));
    }

    public function test_a_section_header_holding_children_is_a_label_and_a_childless_one_is_a_link(): void
    {
        $this->declareOperatorSection('gizmos');
        $header = InvocableNavItem::make(title: 'Gizmos', invocable: 'frame.resources', routeName: 'gizmos.section', href: '/gizmos');
        $nav = NavTree::make([
            $header->stamped(active: false, activeTrail: false, children: [NavLink::make(title: 'Tenants', href: '/operator/tenants')]),
        ])->toArray();
        $lone = NavTree::make([$header])->toArray();

        $invariants = $this->app->make(IaInvariants::class);
        $this->assertSame([], $invariants->violations('operator', $nav));
        // A childless header is a link, and at the top level an operator link sits in no task section either (I3).
        $this->assertSame(['I1 operator /gizmos', 'I3 operator /gizmos', 'I4 operator /gizmos'], array_keys($invariants->violations('operator', $lone)));
    }

    public function test_every_violation_is_reported_at_once(): void
    {
        $nav = NavTree::make([
            NavLink::make(title: 'Operator', href: '/operator/tenants'),
            NavLink::make(title: 'Gone', href: '/reports'),
        ])->toArray();

        $this->assertSame(
            ['I1 tenant /operator/tenants', 'I4 tenant /reports'],
            array_keys($this->app->make(IaInvariants::class)->violations('tenant', $nav)),
        );
    }

    public function test_i6_resolves_resource_operation_named_route_and_explicit_open_arms(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'i6-resources',
            backing: I6FeedBacking::class,
            data: I6ResourceData::class,
            frame: true,
            readOnly: true,
            showable: false,
        ), ['tenant']);
        Route::get('/i6-resources', fn () => [])->name('i6-resources.index')
            ->defaults(ParticleController::RESOURCE, 'i6-resources');

        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'i6-resources', name: 'preview', kind: OperationKind::Read,
            handle: fn () => [], ability: false,
        ));
        Route::get('/i6-resources/preview', fn () => [])->name('i6-resources.preview')
            ->defaults(ParticleOperationController::RESOURCE, 'i6-resources')
            ->defaults(ParticleOperationController::NAME, 'preview');

        Route::get('/i6-admin', fn () => [])->middleware('can:i6.admin')->name('i6.admin');
        Route::get('/i6-help', fn () => [])->name('i6.help')->defaults(SeatGate::OPEN_TO_MEMBERS, true);

        $nav = NavTree::make([
            NavLink::make(title: 'Resources', href: '/i6-resources', routeName: 'i6-resources.index'),
            NavLink::make(title: 'Preview', href: '/i6-resources/preview', routeName: 'i6-resources.preview'),
            NavLink::make(title: 'Admin', href: '/i6-admin', routeName: 'i6.admin'),
            NavLink::make(title: 'Help', href: '/i6-help', routeName: 'i6.help'),
        ])->toArray();

        $this->assertSame([], array_filter(
            $this->app->make(IaInvariants::class)->violations('tenant', $nav),
            fn (string $id): bool => str_starts_with($id, 'I6 '),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function test_i6_a_host_seat_over_an_undeclared_gate_throws(): void
    {
        Route::get('/i6-ambient', fn () => [])->middleware('auth')->name('i6.ambient');
        $nav = NavTree::make([
            NavLink::make(title: 'Ambient', href: '/i6-ambient', routeName: 'i6.ambient'),
        ])->toArray();

        $this->expectException(IaInvariantViolation::class);
        $this->expectExceptionMessageMatches('#I6 tenant /i6-ambient#');

        $this->app->make(IaInvariants::class)->assert('tenant', $nav);
    }

    public function test_i6_a_package_seat_over_an_undeclared_gate_is_pruned(): void
    {
        Route::get('/i6-package-ambient', fn () => [])->middleware('auth')->name('i6.package-ambient');
        $nav = NavTree::make([
            NavLink::make(title: 'Ambient', href: '/i6-package-ambient', routeName: 'i6.package-ambient'),
        ])->toArray();

        $invariants = $this->app->make(IaInvariants::class);
        $this->assertArrayHasKey('I6 tenant /i6-package-ambient', $invariants->violations('tenant', $nav));
        $pruned = $invariants->prune('tenant', $nav);

        $this->assertSame([], $pruned['items']);
    }
}

class I6ResourceData extends \Spatie\LaravelData\Data
{
    public function __construct(public string $id = '') {}
}

class I6FeedBacking implements \Splicewire\Beam\Particle\Backing\StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        return new \Illuminate\Pagination\CursorPaginator([], $perPage);
    }
}
