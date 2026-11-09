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
use Rushing\DataNav\Contracts\NavGateStage;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavGate;
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

    private function flag(?array $entitlement = null, ?NavSeatLock $lock = null, string $href = '/operator/reports', ?string $permission = null, ?array $static = null, NavAudience $audience = NavAudience::Product): void
    {
        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: 'reports', realm: 'operator', label: 'Reports', icon: 'ChartBar', href: $href, order: 20,
            entitlement: $entitlement, permission: $permission, audience: $audience,
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

    public function test_a_reader_who_can_open_nothing_in_the_section_gets_no_seat_and_an_empty_overview(): void
    {
        $this->flag();
        Gate::define('ov.open', fn (): bool => false);
        Gate::define('ov.gated', fn (): bool => false);
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest));
        $this->assertSame([], $this->getJson('frame/resources/operator-reports-overview')->assertOk()->json('data'));
    }

    public function test_a_reader_the_realm_refuses_gets_no_overview(): void
    {
        $this->flag();
        $this->entitlements->keys = [];
        $reader = (new User)->forceFill(['id' => 7]);
        $this->actingAs($reader);

        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
        // The socket's realm gate refuses first, so the policy is asked directly: it is the overview's own boundary.
        $this->assertFalse(Gate::forUser($reader)->allows('section-overview.view.operator.reports'));
    }

    /**
     * The projection's own rule, read before the IA decorator: in the operator realm invariant I3 would also prune an
     * empty seat as a row under no section, which would hide whether the projection dropped it.
     */
    public function test_the_projection_itself_drops_a_seat_gating_left_with_no_child(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => ! str_starts_with((string) $node->routeName, 'ov-')));
        $this->flag();
        $this->actingAs($this->staff());

        $routes = $this->innerRoutes();

        $this->assertNotContains('operator-reports-overview.index', $routes);
        $this->assertNotContains('reports.section', $routes);
    }

    /** @return list<string|null> the top-level route names the inner contribution emits, asked inside a request as the reader */
    private function innerRoutes(): array
    {
        Route::get('ov-inner-probe', function (): array {
            $contributor = app(FrameNavContributor::class);

            return [
                'decorated' => $contributor instanceof IaCheckedNavContributor,
                'routes' => array_column($contributor->inner()->contributeNav('operator')['nav']['items'], 'routeName'),
            ];
        });
        app('router')->getRoutes()->refreshNameLookups();

        $probe = $this->getJson('ov-inner-probe')->assertOk()->json();
        $this->assertTrue($probe['decorated'], 'the inner contribution is read beneath the IA decorator');

        return $probe['routes'];
    }

    public function test_the_projection_keeps_a_flagged_seat_that_has_a_child(): void
    {
        $this->flag();
        $this->actingAs($this->staff());

        $routes = $this->innerRoutes();

        $this->assertContains('operator-reports-overview.index', $routes);
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

    /** A stage that hides the seat is presentation: the page behind it is empty for that reader, not refused. */
    public function test_a_stage_denying_the_seat_hides_it_and_its_overview_is_empty(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => ($node->meta['permission'] ?? null) !== 'ov.token'));
        $this->flag(permission: 'ov.token');
        $this->actingAs($this->staff());

        $this->assertFalse(Gate::has('ov.token'));

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest));
        $this->assertSame([], $this->getJson('frame/resources/operator-reports-overview')->assertOk()->json('data'));
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
     * The stage allows when its attribute is absent, as tower's entitlement stage does with no tenant, and only the host's
     * contributor supplies the attribute. The overview reads the host's own rail, so it draws nothing the rail denies.
     */
    public function test_a_stage_reading_the_hosts_context_hides_the_seat_and_the_overview_is_empty(): void
    {
        $this->app->bind(FrameNavContributor::class, OvTieredContribution::class);
        $this->app->make(NavGate::class)->through(new OvContextStage);
        $this->flag(permission: 'ov.tier');
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest), 'the rail denies');
        $this->assertSame([], $this->getJson('frame/resources/operator-reports-overview')->assertOk()->json('data'));
    }

    public function test_a_host_contributor_of_its_own_decides_the_overview_through_its_own_rail(): void
    {
        $this->app->bind(FrameNavContributor::class, fn (): FrameNavContributor => new OvBareContributor);
        $this->flag();
        $this->actingAs($this->staff());

        $this->assertSame([], $this->getJson('frame/resources/operator-reports-overview')->assertOk()->json('data'));
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

    /**
     * Owner ruling 2026-11-09, the F3 shape: a host stage keyed on a leaf's route name takes that leaf off the projected rail,
     * so its resource draws no card and no tile on the overview, nor on the realm's dashboard, though it is still listable.
     */
    public function test_a_stage_removing_a_declared_resources_leaf_leaves_it_no_card_or_tile_anywhere(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => $node->routeName !== 'ov-sales.index'));
        $this->flag();
        $this->actingAs($this->staff());

        $overview = $this->ids('operator-reports-overview');
        $dashboard = $this->ids('operator-dashboard');

        $this->assertSame([], array_values(array_filter($overview, fn (string $id): bool => str_contains($id, 'ov-sales'))), 'overview');
        $this->assertSame([], array_values(array_filter($dashboard, fn (string $id): bool => str_contains($id, 'ov-sales'))), 'realm dashboard');
        $this->assertContains('summary:ov-costs', $overview, 'the rest of the section is untouched');
    }

    /** The same, for a resource seated only by the section's static row, which no `section:` names. */
    public function test_a_stage_removing_a_static_seated_leaf_leaves_it_no_card_or_tile_anywhere(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => $node->routeName !== 'ov-static.index'));
        $this->flag();
        $this->actingAs($this->staff());

        $overview = $this->ids('operator-reports-overview');
        $dashboard = $this->ids('operator-dashboard');

        $this->assertSame([], array_values(array_filter($overview, fn (string $id): bool => str_contains($id, 'ov-static'))), 'overview');
        $this->assertSame([], array_values(array_filter($dashboard, fn (string $id): bool => str_contains($id, 'ov-static'))), 'realm dashboard');
    }

    /**
     * Owner ruling 2026-11-09: a reader for whom every child is gated out has no seat on the projected rail, and opening the
     * overview anyway gets an empty page, never the rows another reader sees.
     */
    public function test_a_reader_with_every_child_gated_out_has_no_seat_and_an_empty_overview(): void
    {
        $this->app->make(NavGate::class)->through(new OvReaderStage(
            fn (NavNode $node, NavContext $context): bool => ! (($context->user?->getAuthIdentifier() === 8) && str_starts_with((string) $node->routeName, 'ov-')),
        ));
        $this->flag();

        $this->actingAs($this->staff(id: 7));
        $this->assertNotSame([], $this->ids('operator-reports-overview'), 'another reader sees the section');

        $this->actingAs($this->staff(id: 8));
        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertNull($this->seatNode($manifest), 'no seat on the projected rail');
        $this->assertSame([], $this->getJson('frame/resources/operator-reports-overview')->assertOk()->json('data'));
    }

    /** Owner ruling 2026-11-09: the overview's policy is the realm dashboard's ability and the entitlement lock, and asks no stage. */
    public function test_the_overviews_policy_asks_no_nav_stage(): void
    {
        $this->app->make(NavGate::class)->through(new OvCountingStage);
        $this->flag();
        $this->actingAs($this->staff());

        Route::get('ov-policy-probe', function (): array {
            OvCountingStage::$calls = 0;
            $allowed = Gate::allows('section-overview.view.operator.reports');

            return ['allowed' => $allowed, 'calls' => OvCountingStage::$calls];
        });
        app('router')->getRoutes()->refreshNameLookups();

        $this->getJson('ov-policy-probe')->assertOk()->assertExactJson(['allowed' => true, 'calls' => 0]);
    }

    /**
     * Review 03r2 Spec F2: a static row resolves its href route-name-first, as the collector does, so its authored fallback
     * href (here an unflagged resource's) excludes nothing the row does not actually seat.
     */
    public function test_a_static_rows_fallback_href_excludes_no_card_it_does_not_seat(): void
    {
        $this->flag(static: [['title' => 'Static', 'routeName' => 'ov-static.index', 'href' => '/operator/ov-gizmos']]);
        $this->actingAs($this->staff());

        $dashboard = $this->ids('operator-dashboard');

        $this->assertContains('summary:ov-gizmos', $dashboard, 'the unflagged section keeps its card');
        $this->assertNotContains('summary:ov-static', $dashboard, 'the row still seats the resource it names');
    }

    /** The other half of the collector's rule: a row naming no route is matched by its href. */
    public function test_a_static_row_naming_no_route_is_matched_by_its_href(): void
    {
        $this->app->make(NavGate::class)->through(new OvStage(fn (NavNode $node): bool => $node->href !== '/operator/ov-static'));
        $this->flag(static: [['title' => 'Static', 'href' => '/operator/ov-static']]);
        $this->actingAs($this->staff());

        $this->assertNotContains('summary:ov-static', $this->ids('operator-dashboard'));
    }

    /**
     * Review 03r2 Standards F1: a flagged seat in the Developer zone keeps that classification on its overview, so its leaves
     * draw neither cards nor tiles there, as ADR-0224 rules for every Developer leaf.
     */
    public function test_a_flagged_developer_seat_keeps_its_zone_on_its_overview(): void
    {
        $this->flag(audience: NavAudience::Developer);
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();
        $zone = array_values(array_filter($manifest['nav']['items'], fn (array $node): bool => ($node['zone'] ?? null) === 'meta'));
        $this->assertContains('operator-reports-overview.index', array_column($zone[0]['children'] ?? [], 'routeName'), 'the seat sits in the Developer zone');

        $this->assertSame([], $this->getJson('frame/resources/operator-reports-overview')->assertOk()->json('data'));
        $this->assertSame([], array_values(array_filter($this->ids('operator-dashboard'), fn (string $id): bool => str_contains($id, 'reports') || (str_contains($id, 'ov-') && ! str_contains($id, 'ov-gizmos')))));
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

        // Review 03r2 Spec F1: on the realm's dashboard the locked section is still one destination, not its children.
        $section = array_values(array_filter(
            $this->ids('operator-dashboard'),
            fn (string $id): bool => str_contains($id, 'reports') || (str_contains($id, 'ov-') && ! str_contains($id, 'ov-gizmos')),
        ));
        $this->assertSame(['nav:reports.section'], $section);

        // The lock's direct-request refusal is unchanged.
        $this->getJson('frame/resources/operator-reports-overview')->assertForbidden();
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

/** A host's own contributor that does not extend the package's. */
class OvBareContributor implements FrameNavContributor
{
    public function contributeNav(?string $realm): ?array
    {
        return ['nav' => NavTree::make([])->toArray(), 'routeContext' => []];
    }
}

/** A host stage that reads the context as well as the node. */
class OvReaderStage implements NavGateStage
{
    public function __construct(private Closure $decides) {}

    public function allows(NavNode $node, NavContext $context): bool
    {
        return ($this->decides)($node, $context);
    }
}

/** Counts how often it is asked, and allows everything. */
class OvCountingStage implements NavGateStage
{
    public static int $calls = 0;

    public function allows(NavNode $node, NavContext $context): bool
    {
        self::$calls++;

        return true;
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
