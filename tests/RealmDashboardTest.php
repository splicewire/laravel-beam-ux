<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Schemastud\Frame\Attributes\Overview;
use Schemastud\Frame\Attributes\Summary;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Frame\RealmDashboard;
use Splicewire\Beam\Ux\Frame\RouteContextPlan;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;
use Splicewire\Beam\Ux\Tests\Fixtures\FakeEntitlementResolver;

/**
 * The `{realm}-dashboard` resource beam-ux registers per realm (realm-dashboards ticket 04), asserted
 * at the highest seam: the frame socket's rows for a given actor, and the manifest that carries the
 * resource, its router leaf, its nav leaf and its render contexts.
 *
 * ⚠️ **Every drop rule has a fixture that would be a row without it.** `declined` is seated and mounted
 * but its provider declines; `hidden` is seated and would count but opts out; `orphan` is seated and
 * summarizable but the host folds its route; `unseated` is summarizable, mounted and undeclared. A
 * backing that skipped any one rule shows one extra card, and the exact-key assertion catches it.
 */
class RealmDashboardTest extends TestCase
{
    private FakeEntitlementResolver $entitlements;

    /**
     * ⚠️ Frame's provider goes FIRST, not appended as the other frame-aware suites here do. Both frame
     * and beam bind `ResourceAccessGate` — frame to its permit-everything `OpenResourceAccessGate`, beam
     * to the realm gate — and the later binding wins. Appended, frame's would override beam's and the
     * member's 403 below would come back 200 over a green suite. Same order beam's own
     * `ResourceSummaryTest` uses, for the same reason.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app), PermissionCascadeServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('frame.middleware', []);

        // The operator realm is central: with no declared gate its resources — and so its dashboard —
        // are gated on `entitlement:os.operate`, an ability beam defines at boot from the known key
        // universe against whatever resolver is bound. The fake is bound BEFORE boot so the ability
        // exists; which keys it answers is set per test.
        $app['config']->set('app.entitlements', ['os.operate' => []]);
        $app->instance(EntitlementResolver::class, $this->entitlements = new FakeEntitlementResolver);

        // The manifest is realm-blind at this host (no per-realm mount), so the contributor reads the
        // realm off this default — asserted on the manifest below.
        $app['config']->set('beam.ux.frame_nav.default_realm', 'operator');
        $app['config']->set('frame.realms', [
            'operator' => ['gizmos', 'streams', 'declined', 'hidden', 'orphan', 'unseated', 'optin'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dash_gizmos', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DashGizmo::create(['name' => 'a']);
        DashGizmo::create(['name' => 'b']);
        DashGizmo::create(['name' => 'c']);

        // A seat for `platform` in the operator realm, so "nav-seated" has something to be true of.
        $this->app->make(NavSectionRegistry::class)->register(
            new NavSection(key: 'platform', realm: 'operator', label: 'Platform', icon: 'Server', href: '/platform', order: 10, entitlement: null, permission: null),
            by: self::class,
        );

        // `orphan` is FOLDED out of the router: it is seated and summarizable, and the host mounts no
        // route for it — the drop that must not be a throw.
        $this->app->bind(RouteContextPlan::class, fn (): RouteContextPlan => new RouteContextPlan(foldedResources: ['orphan']));

        $registry = $this->app->make(ParticleResourceRegistry::class);

        // Seated, model-backed, default provider: one `total` figure counted through the index query.
        $registry->register(new ParticleResource(
            key: 'gizmos', backing: DashGizmo::class, data: DashGizmoData::class, filterable: false,
            label: 'Gizmos', icon: 'box', section: 'platform', navOrder: 2, readOnly: true,
        ), by: self::class);

        // Seated, streams-only, CUSTOM provider: figures the default could never count.
        $registry->register(new ParticleResource(
            key: 'streams', backing: DashFeed::class, data: DashGizmoData::class, filterable: false,
            label: 'Streams', icon: 'radio', section: 'platform', navOrder: 1, readOnly: true,
            summaryProvider: DashFeedSummaryProvider::class,
        ), by: self::class);

        // Seated, streams-only, DEFAULT provider: declines — an honest absence, not a zero card.
        $registry->register(new ParticleResource(
            key: 'declined', backing: DashFeed::class, data: DashGizmoData::class, filterable: false,
            label: 'Declined', section: 'platform', navOrder: 3, readOnly: true,
        ), by: self::class);

        // Seated and countable, but the Data class says `#[Summary(false)]`.
        $registry->register(new ParticleResource(
            key: 'hidden', backing: DashGizmo::class, data: DashOptedOutData::class, filterable: false,
            label: 'Hidden', section: 'platform', navOrder: 4, readOnly: true,
        ), by: self::class);

        // Seated and countable; the host folds its route.
        $registry->register(new ParticleResource(
            key: 'orphan', backing: DashGizmo::class, data: DashGizmoData::class, filterable: false,
            label: 'Orphan', section: 'platform', navOrder: 5, readOnly: true,
        ), by: self::class);

        // Countable and mounted, but neither seated nor declared: not on the dashboard.
        $registry->register(new ParticleResource(
            key: 'unseated', backing: DashGizmo::class, data: DashGizmoData::class, filterable: false,
            label: 'Unseated', navOrder: 0, readOnly: true,
        ), by: self::class);

        // Unseated but declares `overview`: opted in, and drawn in the overview context.
        $registry->register(new ParticleResource(
            key: 'optin', backing: DashGizmo::class, data: DashOverviewData::class, filterable: false,
            label: 'Opted in', icon: 'eye', readOnly: true,
        ), by: self::class);
    }

    private function staff(): User
    {
        $this->entitlements->keys = ['os.operate'];

        return (new User)->forceFill(['id' => 7]);
    }

    private function member(): User
    {
        $this->entitlements->keys = [];

        return (new User)->forceFill(['id' => 8]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $query = ''): array
    {
        return $this->getJson('frame/resources/operator-dashboard'.$query)->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- registration

    public function test_every_registered_realm_gets_a_dashboard_resource_that_is_a_member_of_that_realm_only(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);

        foreach (array_keys($this->app->make(RealmRegistry::class)->all()) as $realm) {
            $key = RealmDashboard::keyFor($realm);

            $this->assertTrue($registry->has($key), "no dashboard registered for realm [{$realm}]");
            $this->assertSame([$realm], $registry->realmsFor($key));
            $this->assertInstanceOf(DashboardBacking::class, $registry->find($key)->backing);
            $this->assertSame($realm, $registry->find($key)->backing->realm);
        }

        // The read gate is the realm gate's ability, written on the declaration: `entitlement:os.operate`
        // for the central realm with no declared gate, the open ability for a realm that gates nothing.
        $this->assertSame('entitlement:os.operate', $registry->find('operator-dashboard')->policy);
        $this->assertSame(RealmDashboard::OPEN_ABILITY, $registry->find('tenant-dashboard')->policy);
    }

    // ---------------------------------------------------------------- rows per actor

    public function test_a_staff_actor_sees_one_card_per_seated_or_opted_in_resource_in_nav_order_then_the_tiles(): void
    {
        $this->actingAs($this->staff());

        $rows = $this->rows();

        // Cards: streams (1), gizmos (2), opted in (undeclared ⇒ last). Not declined (provider
        // declines), hidden (opt-out), orphan (unmounted), unseated (undeclared, no seat).
        $this->assertSame(
            ['streams', 'gizmos', 'optin'],
            array_column(array_filter($rows, fn (array $row): bool => $row['context'] !== 'nav'), 'resource'),
        );

        $byResource = array_column($rows, null, 'resource');

        $this->assertSame('summary', $byResource['gizmos']['context']);
        $this->assertSame('/operator/gizmos', $byResource['gizmos']['href']);
        $this->assertSame(2, $byResource['gizmos']['navOrder']);
        $this->assertSame('Gizmos', $byResource['gizmos']['label']);
        $this->assertSame('box', $byResource['gizmos']['icon']);
        $this->assertSame([['key' => 'total', 'label' => 'Gizmos', 'value' => 3, 'tone' => null]], $byResource['gizmos']['summary']['figures']);
        $this->assertArrayNotHasKey('href', $byResource['gizmos']['summary'], 'the summary payload is realm-blind; the href is the row\'s');

        $this->assertSame('summary', $byResource['streams']['context']);
        $this->assertSame('open', $byResource['streams']['summary']['figures'][0]['key']);

        // `overview` is chosen when declared, and only then.
        $this->assertSame('overview', $byResource['optin']['context']);

        // Tiles follow every card: the realm's nav leaves for this actor, minus the dashboard's own.
        $tiles = array_values(array_filter($rows, fn (array $row): bool => $row['context'] === 'nav'));
        $this->assertNotEmpty($tiles);
        $this->assertSame(count($rows) - 3, count($tiles));
        $this->assertSame(array_slice($rows, 3), $tiles, 'every tile is after every card');
        $this->assertSame(['Declined', 'Gizmos', 'Hidden', 'Streams'], array_column($tiles, 'label'));
        $this->assertSame('/operator/streams', array_column($tiles, 'href', 'label')['Streams']);
        $this->assertNull($tiles[0]['summary']);
        $this->assertNull($tiles[0]['resource']);
        $this->assertNotContains('/operator/dashboard', array_column($tiles, 'href'));
    }

    public function test_a_member_without_the_realm_entitlement_is_refused_by_the_realm_gate(): void
    {
        $this->actingAs($this->member());

        $this->getJson('frame/resources/operator-dashboard')->assertForbidden();
    }

    public function test_the_per_row_gate_drops_a_resource_the_actor_may_not_list(): void
    {
        // A seated, countable resource behind a declared ability the actor does not hold: absent for
        // that actor, present for one holding it — so a backing that skipped `listable()` fails here.
        \Illuminate\Support\Facades\Gate::define('gizmos.read', fn (User $user): bool => $user->getAuthIdentifier() === 7);
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gated', backing: DashFeed::class, data: DashGizmoData::class, filterable: false,
            label: 'Gated', section: 'platform', navOrder: 0, readOnly: true, policy: 'gizmos.read',
            summaryProvider: DashFeedSummaryProvider::class,
        ), ['operator'], by: self::class);

        $this->actingAs($this->staff());
        $this->assertSame('gated', $this->rows()[0]['resource']);

        $this->entitlements->keys = ['os.operate'];
        $this->actingAs((new User)->forceFill(['id' => 9]));
        $this->assertNotContains('gated', array_column($this->rows(), 'resource'));
    }

    public function test_every_card_is_in_one_page_whatever_per_page_the_request_asks_for(): void
    {
        $this->actingAs($this->staff());

        $this->assertSame(
            array_column($this->rows(), 'id'),
            array_column($this->rows('?perPage=1'), 'id'),
        );
    }

    public function test_the_backing_yields_nothing_for_a_realm_this_host_does_not_have(): void
    {
        $this->assertSame([], (new DashboardBacking('no-such-realm'))->rows());
    }

    /**
     * The null-actor reading of the SECOND gate: a model-less resource is never shown to a guest
     * ({@see \Splicewire\Beam\Authorization\ResourceVisibility::listable()}), while a policy-less
     * model-backed one is — so a guest's rows are exactly the model-backed cards. The dashboard's OWN gate
     * refuses the guest before this backing runs on the socket; this pins the per-row rule in isolation.
     */
    public function test_a_null_actor_is_shown_no_model_less_card(): void
    {
        $resources = array_map(
            fn ($row) => $row->resource,
            array_filter((new DashboardBacking('operator'))->rows(), fn ($row) => ! $row->isTile()),
        );

        $this->assertNotContains('streams', $resources);
        $this->assertContains('gizmos', $resources);
    }

    // ---------------------------------------------------------------- the manifest

    public function test_the_manifest_carries_the_dashboard_resource_its_list_leaf_its_nav_leaf_and_its_contexts(): void
    {
        $this->actingAs($this->staff());

        $manifest = $this->getJson('frame/manifest')->assertOk()->json();

        $this->assertContains('operator-dashboard', array_column($manifest['resources'], 'key'));

        $leaves = array_column($manifest['routeContext'], null, 'routeName');
        $this->assertSame('list', $leaves['operator-dashboard.index']['mounts']);
        $this->assertSame('dashboard', $leaves['operator-dashboard.index']['path']);
        $this->assertSame('operator-dashboard', $leaves['operator-dashboard.index']['resource']);
        $this->assertArrayNotHasKey('operator-dashboard.edit', $leaves, 'showable: false, editable: false ⇒ no :id twin');

        // The section-less realm-level leaf, first, at order zero.
        $first = $manifest['nav']['items'][0];
        $this->assertSame('operator-dashboard.index', $first['routeName']);
        $this->assertSame('/operator/dashboard', $first['href']);
        $this->assertSame('Dashboard', $first['title']);
        $this->assertSame([], $first['children']);

        // The gap ticket 01's review nominated: the contexts block, read off the HTTP response.
        $this->assertSame(
            ['participates' => true, 'widget' => 'dashboard-card'],
            $manifest['contexts']['operator-dashboard']['byNode']['']['list-item'],
        );
        $this->assertSame(['summary'], $manifest['contexts']['gizmos']['inherits']['overview']);
        $this->assertSame(
            ['participates' => true, 'widget' => 'figure-card'],
            $manifest['contexts']['optin']['byNode']['']['overview'],
        );
    }

    public function test_the_nav_leaf_is_absent_for_an_actor_the_dashboard_refuses(): void
    {
        // A principal outside the realm gate: the realm-blind manifest still answers, and the
        // dashboard leaf is simply not there — absent, not locked, not empty.
        $this->actingAs($this->member());

        $routeNames = array_column($this->getJson('frame/manifest')->assertOk()->json('nav.items'), 'routeName');

        $this->assertNotContains('operator-dashboard.index', $routeNames);
    }
}

class DashGizmo extends Model
{
    public $timestamps = false;

    protected $table = 'dash_gizmos';

    protected $guarded = [];
}

class DashGizmoData extends Data
{
    public function __construct(public int $id = 0, public string $name = '') {}
}

#[Summary(false)]
class DashOptedOutData extends Data
{
    public function __construct(public int $id = 0, public string $name = '') {}
}

#[Overview('figure-card')]
class DashOverviewData extends Data
{
    public function __construct(public int $id = 0, public string $name = '') {}
}

/** A streams-only backing with no model: the default provider cannot count it. */
class DashFeed implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class DashFeedSummaryProvider implements ResourceSummaryProvider
{
    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        return new SummaryResponseData(
            key: $resource->key,
            label: $resource->nav->label,
            icon: $resource->nav->icon,
            figures: [new SummaryFigureData(key: 'open', label: 'Open', value: 4)],
            overview: null,
        );
    }
}
