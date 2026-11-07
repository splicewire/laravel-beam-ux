<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Schemastud\Frame\Attributes\Summary;
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
use Splicewire\Beam\Ux\Frame\RealmConsoleSegments;
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

    private function flag(?array $entitlement = null, ?NavSeatLock $lock = null, string $href = '/operator/reports'): void
    {
        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: 'reports', realm: 'operator', label: 'Reports', icon: 'ChartBar', href: $href, order: 20,
            entitlement: $entitlement, permission: null, audience: NavAudience::Product,
            static: [['title' => 'Static', 'href' => '/operator/ov-static', 'routeName' => 'ov-static.index']],
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
