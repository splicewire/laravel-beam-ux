<?php

namespace Splicewire\Beam\Ux\Tests;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Rushing\DataNav\Contracts\NavGateStage;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavGate;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavNode;
use Rushing\DataNav\NavTree;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Schemastud\Frame\Attributes\Summary;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Dashboard\SectionOverview;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSeatLock;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\RealmConsoleSegments;
use Splicewire\Beam\Ux\Ia\IaCheckedNavContributor;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;
use Splicewire\Beam\Ux\Tests\Fixtures\FakeEntitlementResolver;

/**
 * A flagged section's overview (ADR-0222 decision 9), asserted at the highest seam: the frame socket's rows and the manifest
 * for a given actor. `reports` is the flagged fixture section and `platform` an unflagged one beside it, so confining too
 * little or too much shows in an exact row list. Nothing here names a package that uses the flag.
 */
class SectionOverviewTest extends TestCase
{
    private FakeEntitlementResolver $entitlements;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class, PermissionCascadeServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('frame.middleware', []);
        $app['config']->set('app.entitlements', ['os.operate' => [], 'reports.view' => [], 'ux.site.author' => []]);
        $app->instance(EntitlementResolver::class, $this->entitlements = new FakeEntitlementResolver);
        $app['config']->set('beam.ux.frame_nav.default_realm', 'operator');
        $app['config']->set('frame.realms', [
            'operator' => ['ov-sales', 'ov-costs', 'ov-gated', 'ov-static', 'ov-plain', 'ov-throws', 'ov-gizmos'],
            'tenant' => [],
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('operator/{path?}', fn () => 'operator shell')->where('path', '.*')
            ->defaults(SeatGate::OPEN_TO_MEMBERS, true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ov_gizmos', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        OvGizmo::create(['name' => 'a']);

        Gate::define('ov.gated', fn (?User $user = null): bool => ($user?->id ?? 0) === 7);

        $sections = $this->app->make(NavSectionRegistry::class);
        $sections->register(new NavSection(
            key: 'platform', realm: 'operator', label: 'Platform', icon: 'Server', href: '/platform', order: 10,
            entitlement: null, permission: null, audience: NavAudience::Product,
        ), by: self::class);

        $registry = $this->app->make(ParticleResourceRegistry::class);

        $card = fn (string $key, ?string $section, array $extra = []) => $registry->register(new ParticleResource(...[
            'key' => $key,
            'backing' => OvFeed::class,
            'data' => OvCardData::class,
            'label' => ucfirst(substr($key, 3)),
            'icon' => 'box',
            'section' => $section,
            'summaryProvider' => OvSummaryProvider::class,
            'policy' => 'ov.open',
            'readOnly' => true,
            ...$extra,
        ]), by: self::class);

        Gate::define('ov.open', fn (?User $user = null): bool => $user !== null);

        $card('ov-sales', 'reports', ['navOrder' => 1]);
        $card('ov-costs', 'reports', ['navOrder' => 2]);
        $card('ov-gated', 'reports', ['navOrder' => 3, 'policy' => 'ov.gated']);
        // Seated by the flagged section's static child alone, with no `section:` of its own.
        $card('ov-static', null);
        // A destination in the section that declares no card: a tile on the overview.
        $registry->register(new ParticleResource(
            key: 'ov-plain', backing: OvFeed::class, data: OvPlainData::class, label: 'Plain', icon: 'box', section: 'reports', policy: 'ov.open', navOrder: 5, readOnly: true,
        ), by: self::class);
        $card('ov-throws', 'reports', ['summaryProvider' => OvThrowingProvider::class, 'navOrder' => 6]);
        // The unflagged section beside it.
        $registry->register(new ParticleResource(
            key: 'ov-gizmos', backing: OvGizmo::class, data: OvCardData::class, label: 'Gizmos', icon: 'box', section: 'platform',
        ), by: self::class);
    }

    private function flag(?array $entitlement = null, ?NavSeatLock $lock = null, string $href = '/operator/reports', ?string $permission = null, ?array $static = null): void
    {
        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: 'reports', realm: 'operator', label: 'Reports', icon: 'ChartBar', href: $href, order: 20,
            entitlement: $entitlement, permission: $permission, audience: NavAudience::Product,
            static: $static ?? [['title' => 'Static', 'href' => '/operator/ov-static', 'routeName' => 'ov-static.index']],
            lock: $lock, overview: true,
        ), by: self::class);

        (new \ReflectionMethod($this->app->getProvider(\Splicewire\Beam\Ux\BeamUxServiceProvider::class), 'registerSectionOverviews'))
            ->invoke($this->app->getProvider(\Splicewire\Beam\Ux\BeamUxServiceProvider::class));
    }

    private function staff(int $id = 7): User
    {
        $this->entitlements->keys = ['os.operate', 'reports.view'];

        return (new User)->forceFill(['id' => $id]);
    }

    /** @return list<string> */
    private function ids(string $resource): array
    {
        return array_column($this->getJson('frame/resources/'.$resource)->assertOk()->json('data'), 'id');
    }

    private function seatNode(array $manifest): ?array
    {
        foreach ($manifest['nav']['items'] as $node) {
            if (($node['title'] ?? null) === 'Reports') {
                return $node;
            }
        }

        return null;
    }

    public function test_a_flagged_section_gets_a_read_only_resource_gated_on_its_declared_ability(): void
    {
        $this->flag();

        $resource = $this->app->make(ParticleResourceRegistry::class)->get('operator-reports-overview');

        $this->assertSame('section-overview.view.operator.reports', $resource->policy);
        $this->assertSame('reports', $resource->backing->section);
        $this->assertTrue($resource->readOnly);
        $this->assertFalse($resource->showable);
        $this->assertSame(['operator'], $this->app->make(ParticleResourceRegistry::class)->realmsFor('operator-reports-overview'));
        $this->assertFalse($this->app->make(ParticleResourceRegistry::class)->has('operator-platform-overview'));
    }

    public function test_cards_and_tiles_are_confined_to_the_section_with_a_static_child_included(): void
    {
        $this->flag();
        $this->actingAs($this->staff());

        $this->assertSame(
            ['summary:ov-sales', 'summary:ov-costs', 'summary:ov-gated', 'summary:ov-static', 'nav:ov-plain.index', 'nav:ov-throws.index'],
            $this->ids('operator-reports-overview'),
        );
    }

    public function test_the_per_row_gate_applies_on_an_overview(): void
    {
        $this->flag();
        $this->actingAs($this->staff(id: 8));

        $this->assertNotContains('summary:ov-gated', $this->ids('operator-reports-overview'));
    }

    public function test_a_throwing_provider_is_rolled_back_to_a_savepoint_on_an_overview_and_falls_to_its_tile(): void
    {
        $this->flag();
        $this->actingAs($this->staff());

        DB::beginTransaction();
        $ids = $this->ids('operator-reports-overview');
        $this->assertSame(1, DB::transactionLevel(), 'the open transaction survived');
        DB::rollBack();

        $this->assertContains('nav:ov-throws.index', $ids);
        $this->assertContains('summary:ov-sales', $ids);
    }

    public function test_on_the_realm_dashboard_a_flagged_section_is_one_destination(): void
    {
        $this->flag();
        $this->actingAs($this->staff());

        $rows = $this->getJson('frame/resources/operator-dashboard')->assertOk()->json('data');
        $ids = array_column($rows, 'id');

        $this->assertSame(['nav:'.SectionOverview::routeNameFor('operator', 'reports')], array_values(array_filter($ids, fn (string $id): bool => str_contains($id, 'ov-') && ! str_contains($id, 'gizmos') || str_contains($id, 'reports'))));

        $tile = $rows[array_search('nav:operator-reports-overview.index', $ids, true)];
        $this->assertSame('Reports', $tile['label']);
        $this->assertSame('/operator/reports', $tile['href']);
        $this->assertContains('summary:ov-gizmos', $ids, 'the unflagged section beside it still draws its card');
    }

    public function test_the_seat_is_bound_to_the_overview_leaf_at_its_own_href(): void
    {
        $this->flag();
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();
        $seat = $this->seatNode($manifest);
        $leaves = array_column($manifest['routeContext'], null, 'routeName');

        $this->assertSame('operator-reports-overview.index', $seat['routeName']);
        $this->assertSame('/operator/reports', $seat['href']);
        $this->assertSame('list', $leaves['operator-reports-overview.index']['mounts']);
        $this->assertSame('reports', $leaves['operator-reports-overview.index']['path']);
        $this->assertSame('operator-reports-overview', $leaves['operator-reports-overview.index']['resource']);
        $this->assertContains('ov-sales.index', array_column($seat['children'], 'routeName'), 'the section keeps its children in the rail');
        $this->assertNotContains('operator-reports-overview.index', array_column($seat['children'], 'routeName'));
        $this->assertContains('reports', RealmConsoleSegments::for('operator', 'operator'));
    }

    public function test_a_seat_href_outside_the_realm_base_still_resolves_to_one_url(): void
    {
        $this->flag(href: '/reports');
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertSame('/operator/reports', $this->seatNode($manifest)['href']);
    }

    public function test_a_reader_who_can_open_nothing_in_the_section_gets_no_seat_and_no_overview(): void
    {
        $this->flag();
        Gate::define('ov.open', fn (): bool => false);
        Gate::define('ov.gated', fn (): bool => false);
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNotContains('operator-reports-overview.index', array_column($manifest['routeContext'], 'routeName'));
        $this->assertNull($this->seatNode($manifest));
        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_reader_the_realm_refuses_gets_no_overview(): void
    {
        $this->flag();
        $this->entitlements->keys = [];
        $this->actingAs((new User)->forceFill(['id' => 7]));

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_reader_the_seats_hard_gate_refuses_gets_no_seat_and_no_overview(): void
    {
        $this->flag(entitlement: ['reports.view']);
        $this->entitlements->keys = ['os.operate'];
        $this->actingAs((new User)->forceFill(['id' => 7]));

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest));
        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_stage_denying_a_section_token_with_no_gate_definition_refuses_seat_leaf_and_request(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => ($node->meta['permission'] ?? null) !== 'ov.token'));
        $this->flag(permission: 'ov.token');
        $this->actingAs($this->staff());

        $this->assertFalse(Gate::has('ov.token'));

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest));
        $this->assertNotContains('operator-reports-overview.index', array_column($manifest['routeContext'], 'routeName'));
        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_host_with_no_stage_and_an_undefined_token_does_not_start_refusing(): void
    {
        $this->flag(permission: 'ov.undefined');
        $this->actingAs($this->staff());

        $this->assertFalse(Gate::has('ov.undefined'));
        $this->assertNotNull($this->seatNode($this->getJson('frame/manifest')->assertOk()->json()));
        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    public function test_a_gate_ability_under_a_token_gates_nothing_without_a_stage_as_on_the_rail(): void
    {
        Gate::define('ov.defined', fn (): bool => false);
        $this->flag(permission: 'ov.defined');
        $this->actingAs($this->staff());

        $this->assertNotNull($this->seatNode($this->getJson('frame/manifest')->assertOk()->json()));
        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    /**
     * The charter's ruling: the stage allows when its attribute is absent, as tower's entitlement stage does with no tenant,
     * and only the host's contributor supplies the attribute. A gate building its own context would admit what the rail denies.
     */
    public function test_a_stage_allowing_on_an_absent_attribute_denies_on_the_rail_and_on_the_overview_alike(): void
    {
        $this->app->bind(FrameNavContributor::class, OvTieredContribution::class);
        $this->app->make(NavGate::class)->through(new OvContextStage);
        $this->flag(permission: 'ov.tier');
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest), 'the rail denies');
        $this->assertNotContains('operator-reports-overview.index', array_column($manifest['routeContext'], 'routeName'));
        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_asking_for_the_context_reads_no_tree_so_a_direct_request_cannot_recurse(): void
    {
        $this->app->bind(FrameNavContributor::class, OvCountingContribution::class);
        OvCountingContribution::$built = 0;
        $this->flag();
        $this->actingAs($this->staff());

        // Inside a real request, with no nav build open: the gate alone, then the tree count.
        Route::get('ov-gate-probe', fn (): array => [
            'build_open' => app()->bound(NavContext::class),
            'allowed' => Gate::allows('section-overview.view.operator.reports'),
            'built' => OvCountingContribution::$built,
        ]);
        app('router')->getRoutes()->refreshNameLookups();

        $this->getJson('ov-gate-probe')->assertOk()->assertExactJson(['build_open' => false, 'allowed' => true, 'built' => 0]);

        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
        $this->assertSame(1, OvCountingContribution::$built, 'only the backing\'s own rail read built one');
    }

    public function test_the_ia_checked_decorator_forwards_the_context(): void
    {
        $this->app->bind(FrameNavContributor::class, OvTieredContribution::class);
        $this->actingAs($this->staff());

        $contributor = $this->app->make(FrameNavContributor::class);

        $this->assertInstanceOf(IaCheckedNavContributor::class, $contributor);
        $this->assertSame('denied', $contributor->navContextFor('operator')->attribute('ov.tier'));
    }

    public function test_a_contributor_without_the_interface_means_the_overview_refuses(): void
    {
        $this->app->bind(FrameNavContributor::class, fn (): FrameNavContributor => new OvBareContributor);
        $this->flag();
        $this->actingAs($this->staff());

        $this->assertInstanceOf(IaCheckedNavContributor::class, $this->app->make(FrameNavContributor::class));
        $this->assertNull($this->app->make(FrameNavContributor::class)->navContextFor('operator'));
        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_context_about_another_user_means_the_overview_refuses(): void
    {
        OvOtherUserContribution::$user = (new User)->forceFill(['id' => 99]);
        $this->app->bind(FrameNavContributor::class, OvOtherUserContribution::class);
        $this->flag();
        $this->actingAs($this->staff());

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_context_with_no_user_means_the_overview_refuses(): void
    {
        OvOtherUserContribution::$user = null;
        $this->app->bind(FrameNavContributor::class, OvOtherUserContribution::class);
        $this->flag();
        $this->actingAs($this->staff());

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_context_that_throws_means_the_overview_refuses(): void
    {
        $this->app->bind(FrameNavContributor::class, OvThrowingContribution::class);
        $this->flag();
        $this->actingAs($this->staff());

        $this->assertFalse(Gate::forUser($this->staff())->allows('section-overview.view.operator.reports'));
    }

    /** Tower's shape: the same person projected as another model object sharing the key. */
    public function test_a_context_projecting_the_same_person_as_another_object_is_still_that_reader(): void
    {
        OvOtherUserContribution::$user = (new User)->forceFill(['id' => 7, 'email' => 'mirror@example.test']);
        $this->app->bind(FrameNavContributor::class, OvOtherUserContribution::class);
        $this->flag();
        $this->actingAs($this->staff());

        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    public function test_a_stock_contribution_admits_its_reader_on_both_the_manifest_and_a_direct_request(): void
    {
        $this->flag();
        $this->actingAs($this->staff());

        $this->assertInstanceOf(FrameNavContribution::class, $this->app->make(FrameNavContributor::class)->inner());
        $this->assertNotNull($this->seatNode($this->getJson('frame/manifest')->assertOk()->json()));
        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    public function test_a_stage_that_admits_the_token_admits_the_overview(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => true));
        $this->flag(permission: 'ov.token');
        $this->actingAs($this->staff());

        $this->assertNotNull($this->seatNode($this->getJson('frame/manifest')->assertOk()->json()));
        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    public function test_a_stage_denying_every_child_leaves_nothing_to_open(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => ! str_starts_with((string) $node->routeName, 'ov-')));
        $this->flag();
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest));
        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    /** Closes every resource child, so the one static row decides. */
    private function onlyTheStaticDecides(array $static): void
    {
        Gate::define('ov.open', fn (): bool => false);
        Gate::define('ov.gated', fn (): bool => false);
        $this->flag(static: [$static]);
        $this->actingAs($this->staff());
    }

    public function test_an_unnamed_static_on_a_local_route_that_denies_the_reader_is_not_open(): void
    {
        Gate::define('ov.never', fn (): bool => false);
        Route::get('ov-restricted', fn () => 'restricted')->middleware('can:ov.never');
        app('router')->getRoutes()->refreshNameLookups();

        $this->onlyTheStaticDecides(['title' => 'Restricted', 'href' => '/ov-restricted']);

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_an_unnamed_static_on_a_local_route_that_admits_the_reader_is_open(): void
    {
        Gate::define('ov.always', fn (): bool => true);
        Route::get('ov-allowed', fn () => 'allowed')->middleware('can:ov.always');
        app('router')->getRoutes()->refreshNameLookups();

        $this->onlyTheStaticDecides(['title' => 'Allowed', 'href' => '/ov-allowed']);

        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    public function test_an_unnamed_static_on_no_route_at_all_is_not_open(): void
    {
        $this->onlyTheStaticDecides(['title' => 'Nowhere', 'href' => '/ov-no-such-page']);

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_an_external_static_counts_as_open_under_the_stated_rule_and_a_stage_can_still_refuse_it(): void
    {
        $this->onlyTheStaticDecides(['title' => 'Docs', 'href' => 'https://docs.example.test/reports']);

        $this->getJson('frame/resources/operator-reports-overview')->assertOk();

        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => $node->title !== 'Docs'));

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function sameOriginForms(): array
    {
        return ['absolute' => ['http://localhost'], 'absolute on another scheme' => ['https://localhost'], 'scheme-relative' => ['//localhost'], 'another case' => ['HTTP://LOCALHOST']];
    }

    #[DataProvider('sameOriginForms')]
    public function test_a_same_origin_static_on_a_route_that_denies_the_reader_is_not_open(string $origin): void
    {
        Gate::define('ov.never', fn (): bool => false);
        Route::get('ov-restricted', fn () => 'restricted')->middleware('can:ov.never');
        app('router')->getRoutes()->refreshNameLookups();

        $this->onlyTheStaticDecides(['title' => 'Restricted', 'href' => $origin.'/ov-restricted']);

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    #[DataProvider('sameOriginForms')]
    public function test_a_same_origin_static_on_a_route_that_admits_the_reader_is_open(string $origin): void
    {
        Gate::define('ov.always', fn (): bool => true);
        Route::get('ov-allowed', fn () => 'allowed')->middleware('can:ov.always');
        app('router')->getRoutes()->refreshNameLookups();

        $this->onlyTheStaticDecides(['title' => 'Allowed', 'href' => $origin.'/ov-allowed']);

        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function otherOriginForms(): array
    {
        return ['absolute' => ['https://docs.example.test/ov-restricted'], 'scheme-relative' => ['//docs.example.test/ov-restricted'], 'same host, another port' => ['http://localhost:8080/ov-restricted']];
    }

    #[DataProvider('otherOriginForms')]
    public function test_a_genuinely_different_origin_counts_as_external(string $href): void
    {
        $this->onlyTheStaticDecides(['title' => 'Elsewhere', 'href' => $href]);

        $this->getJson('frame/resources/operator-reports-overview')->assertOk();
    }

    public function test_a_non_web_scheme_with_a_host_is_not_open(): void
    {
        $this->onlyTheStaticDecides(['title' => 'Files', 'href' => 'ftp://docs.example.test/reports']);

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_another_scheme_is_not_open(): void
    {
        $this->onlyTheStaticDecides(['title' => 'Mail', 'href' => 'mailto:reports@example.test']);

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    /**
     * A console host binds a request built from `app.url` (Laravel's SetRequestForConsole), and frame's registry needs one, so
     * no real path reaches admission with none. The branch is asked directly, to pin that it fails closed if one ever does.
     */
    private function opensWithNoRequest(string $href): bool
    {
        Gate::define('ov.never', fn (): bool => false);
        Route::get('ov-restricted', fn () => 'restricted')->middleware('can:ov.never');
        app('router')->getRoutes()->refreshNameLookups();
        $user = $this->staff();
        $invocable = $this->app->make(FrameResourcesInvocable::class);
        $this->app->forgetInstance('request');
        $this->assertFalse($this->app->bound('request'));

        return (new \ReflectionMethod($invocable, 'opens'))->invoke($invocable, NavLink::make(title: 'Row', href: $href), 'operator', $user, []);
    }

    public function test_with_no_request_app_url_decides_what_is_this_hosts_own_origin(): void
    {
        config(['app.url' => 'https://app.example.test']);

        $this->assertFalse($this->opensWithNoRequest('https://app.example.test/ov-restricted'), 'own origin: the route denies');
    }

    public function test_with_no_request_another_origin_than_app_url_is_external(): void
    {
        config(['app.url' => 'https://app.example.test']);

        $this->assertTrue($this->opensWithNoRequest('https://docs.example.test/ov-restricted'));
    }

    public function test_with_no_request_and_no_app_url_an_absolute_href_is_not_open(): void
    {
        config(['app.url' => null]);

        $this->assertFalse($this->opensWithNoRequest('https://docs.example.test/ov-restricted'));
    }

    public function test_a_fragment_static_goes_nowhere_and_is_not_open(): void
    {
        $this->onlyTheStaticDecides(['title' => 'Here', 'href' => '#here']);

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_a_soft_locked_seat_stays_visible_and_locked_with_no_landing(): void
    {
        $this->flag(entitlement: ['reports.view'], lock: new NavSeatLock('Upgrade', 'plan'));
        $this->entitlements->keys = ['os.operate'];
        $this->actingAs((new User)->forceFill(['id' => 7]));

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();
        $seat = $this->seatNode($manifest);

        $this->assertNotNull($seat);
        $this->assertSame('reports.section', $seat['routeName']);
        $this->assertNotNull($seat['locked']);
        $this->assertNotContains('operator-reports-overview.index', array_column($manifest['routeContext'], 'routeName'));
    }

    public function test_a_host_may_narrow_the_overview_by_defining_its_ability_first(): void
    {
        Gate::define('section-overview.view.operator.reports', fn (): bool => false);
        $this->flag();
        $this->actingAs($this->staff());

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $this->flag();
        $before = $this->app->make(ParticleResourceRegistry::class)->get('operator-reports-overview');
        $this->flagAgain();

        $this->assertSame($before, $this->app->make(ParticleResourceRegistry::class)->get('operator-reports-overview'));
    }

    private function flagAgain(): void
    {
        (new \ReflectionMethod($this->app->getProvider(\Splicewire\Beam\Ux\BeamUxServiceProvider::class), 'registerSectionOverviews'))
            ->invoke($this->app->getProvider(\Splicewire\Beam\Ux\BeamUxServiceProvider::class));
    }

    public function test_an_overview_with_no_rows_has_no_first_run_panel(): void
    {
        $this->flag();
        Gate::define('ov.open', fn (): bool => false);
        Gate::define('ov.gated', fn (): bool => false);
        $this->actingAs($this->staff());

        $this->assertSame([], (new DashboardBacking('operator', 'reports'))->rows());
    }
}

/** A host's nav gate stage, as tower's permission stage is: it reads the node and the context, and names no Gate ability. */
class OvStage implements NavGateStage
{
    public function __construct(private Closure $decides) {}

    public function allows(NavNode $node, NavContext $context): bool
    {
        return ($this->decides)($node);
    }
}

/** Denies the seat only when the contributor's attribute says so, and allows when the attribute is absent. */
class OvContextStage implements NavGateStage
{
    public function allows(NavNode $node, NavContext $context): bool
    {
        return ($node->meta['permission'] ?? null) !== 'ov.tier' || $context->attribute('ov.tier') !== 'denied';
    }
}

/** A host's contributor with a richer context, in the shape of tower's. */
class OvTieredContribution extends FrameNavContribution
{
    protected function context(string $realm): NavContext
    {
        $base = parent::context($realm);

        return new NavContext(user: $base->user, request: $base->request, attributes: [...$base->attributes, 'ov.tier' => 'denied']);
    }
}

/** Counts tree builds, to show the gate causes none. */
class OvCountingContribution extends FrameNavContribution
{
    public static int $built = 0;

    public function contributeNav(?string $realm): ?array
    {
        self::$built++;

        return parent::contributeNav($realm);
    }
}

/** A contributor that hands its stages a context about someone else, or nobody. */
class OvOtherUserContribution extends FrameNavContribution
{
    public static ?User $user = null;

    protected function context(string $realm): NavContext
    {
        $base = parent::context($realm);

        return new NavContext(user: self::$user, request: $base->request, attributes: $base->attributes);
    }
}

class OvThrowingContribution extends FrameNavContribution
{
    public function navContextFor(string $realm): ?NavContext
    {
        throw new RuntimeException('no tenant here');
    }
}

/** A host's own contributor that does not extend the package's. */
class OvBareContributor implements FrameNavContributor
{
    public function contributeNav(?string $realm): ?array
    {
        return ['nav' => NavTree::make([])->toArray(), 'routeContext' => []];
    }
}

class OvGizmo extends Model
{
    protected $table = 'ov_gizmos';

    public $timestamps = false;

    protected $guarded = [];
}

#[Summary]
class OvCardData extends Data
{
    public function __construct(public int $id = 0, public string $name = '') {}
}

class OvPlainData extends Data
{
    public function __construct(public int $id = 0, public string $name = '') {}
}

class OvFeed implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], 1, null, ['parameters' => ['id']]);
    }
}

class OvSummaryProvider implements ResourceSummaryProvider
{
    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        return new SummaryResponseData(
            key: $resource->key,
            label: $resource->nav->label,
            icon: $resource->nav->icon,
            figures: [new SummaryFigureData(key: 'total', label: 'Total', value: 1)],
            overview: null,
        );
    }
}

class OvThrowingProvider implements ResourceSummaryProvider
{
    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        DB::statement('select * from a_table_that_does_not_exist');

        return null;
    }
}
