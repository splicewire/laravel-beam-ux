<?php

namespace Splicewire\Beam\Ux\Tests;

use Schemastud\Frame\Contracts\FrameNavContributor;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\RouteContextPlan;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;

/**
 * The promoted router projection — the engine that used to exist at exactly ONE host, in a
 * hand-written copy of frame's manifest controller.
 *
 * ⚠️ **Every fixture here is built so the two realms give DIFFERENT answers.** `operator` is
 * central and holds one resource; `tenant` is scoped and holds four. A suite whose fixture hosts
 * shared a realm shape would let a projector that ignored the realm entirely — returned every
 * resource to every realm, joined every href without a shell — pass every assertion, which is
 * this estate's signature defect (AGENTS.md §*an instrument that reports success by not running*).
 */
class FrameRouteContextProjectionTest extends TestCase
{
    /**
     * ⚠️ Testbench does not auto-discover. `FrameServiceProvider` is what binds the
     * {@see ResourceRegistry} port onto the `frame.resources` index — without it the interface is
     * simply unbound and every assertion here fails with `not instantiable`, which at least fails
     * LOUDLY. The same omission on a class that stays auto-resolvable is the silent version
     * (AGENTS.md §*Testbench does not auto-discover*). Overridden here rather than added to the
     * shared TestCase so the rest of this suite keeps the provider set it was measured against.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Realm MEMBERSHIP is a host-side list (api-surface-coherence 142), and the two realms are
        // deliberately not the same list — that asymmetry is what makes the realm assertions real.
        $app['config']->set('frame.realms', [
            'operator' => ['tenants'],
            'tenant' => ['circuits', 'fragments', 'invitations', 'audit'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Registered on the PARTICLE registry, not on frame's port directly: realm membership is
        // read through `ParticleResourceRegistry::keysForRealm()`, which enumerates its OWN
        // entries. A fixture registered only on frame's composite is visible to `all()` and
        // invisible to the membership half — so every realm would come back empty and the suite
        // would be asserting an empty projection. Beam attaches this registry as a member of
        // frame's composite, so one registration serves both halves.
        $registry = $this->app->make(\Splicewire\Beam\Particle\ParticleResourceRegistry::class);

        // ⚠️ `creatable` and `editable` are decoupled ON PURPOSE, and `invitations` is the reason.
        // With `readOnly: ! $editable` the two flags moved together, and a projector gating the
        // record twin on the WRONG one (`creatable`, which is what this code did until it was
        // caught in the flagship) produced byte-identical output — a mutation that should have gone
        // red came back green. `invitations` is the documented live shape: creatable and deletable,
        // never editable. A fixture whose resources all agree cannot see the decision it is testing.
        foreach ([
            // key            routeName            readOnly editable showable
            ['tenants', 'tenants.index', true, false, true],
            ['circuits', 'circuits.index', false, true, true],
            ['fragments', 'fragments.index', false, true, true],
            ['invitations', 'invitations.index', false, false, false],
            ['audit', 'audit.index', true, false, true],
        ] as [$key, $routeName, $readOnly, $editable, $showable]) {
            $registry->register(new ParticleResource(
                key: $key,
                backing: 'Acme\\Particles\\'.str_replace('-', '', ucfirst($key)),
                data: FixtureResourceData::class,
                label: ucfirst($key),
                routeName: $routeName,
                readOnly: $readOnly,
                editable: $editable,
                showable: $showable,
            ), by: self::class);
        }
    }

    public function test_the_two_realms_project_different_leaves(): void
    {
        $projector = $this->projector();

        $this->assertSame(
            ['tenants.index', 'tenants.edit'],
            $this->routeNames($projector->routeContext('operator'))
        );

        $this->assertSame(
            ['circuits.index', 'circuits.edit', 'fragments.index', 'fragments.edit', 'invitations.index', 'audit.index', 'audit.edit'],
            $this->routeNames($projector->routeContext('tenant'))
        );
    }

    public function test_the_single_record_twin_follows_the_declaration_not_the_key(): void
    {
        $byName = $this->byName($this->projector()->routeContext('tenant'));

        // `editable` ⇒ an edit form.
        $this->assertSame('edit', $byName['circuits.edit']->mounts);
        // `showable && ! editable` ⇒ a read-only detail, NOT an edit shell whose save the server 405s.
        $this->assertSame('detail', $byName['audit.edit']->mounts);
        // Neither ⇒ no twin at all — and `invitations` is CREATABLE, so a projector gating the twin
        // on `creatable` would emit one here. That is the mutation this assertion exists to catch,
        // and it only catches it because the fixture lets the two flags disagree.
        $invitations = null;
        foreach ($this->app->make(ResourceRegistry::class)->all() as $definition) {
            if ($definition->key === 'invitations') {
                $invitations = $definition;
            }
        }
        $this->assertNotNull($invitations);
        $this->assertTrue($invitations->creatable, 'The fixture must keep creatable and editable able to disagree, or the gate cannot be observed.');
        $this->assertArrayNotHasKey('invitations.edit', $byName);
    }

    public function test_each_host_list_changes_the_projection_it_names(): void
    {
        $plan = new RouteContextPlan(
            heavyweightEditors: ['circuits' => 'circuit-graph'],
            shelledResources: ['fragments' => ['shell' => 'knowledge', 'path' => 'fragments']],
            resourcePaths: ['audit' => 'history'],
            foldedResources: ['invitations'],
            shellBases: ['app' => '', 'knowledge' => 'knowledge'],
            scopedStandalone: [['routeName' => 'calendar', 'path' => 'calendar', 'mounts' => 'detail']],
        );

        $byName = $this->byName($this->projector($plan)->routeContext('tenant'));

        // heavyweightEditors — a widget mount, emitted lazy.
        $this->assertSame('widget', $byName['circuits.edit']->mounts);
        $this->assertSame('circuit-graph', $byName['circuits.edit']->widget);
        $this->assertTrue($byName['circuits.edit']->lazy);

        // shelledResources — nests the LIST leaf and emits NO edit twin.
        $this->assertSame('knowledge', $byName['fragments.index']->shell);
        $this->assertArrayNotHasKey('fragments.edit', $byName);

        // resourcePaths — the URL moves, the routeName (stable identity) does not.
        $this->assertSame('history', $byName['audit.index']->path);
        $this->assertSame('history/:id', $byName['audit.edit']->path);

        // foldedResources — no leaf at all.
        $this->assertArrayNotHasKey('invitations.index', $byName);

        // standalone — a resource-less leaf, inheriting the realm's guard.
        $this->assertSame('calendar', $byName['calendar']->path);
        $this->assertNull($byName['calendar']->resource);
    }

    public function test_detailless_suppresses_the_detail_arm_and_nothing_else(): void
    {
        $with = $this->byName($this->projector()->routeContext('tenant'));
        $without = $this->byName($this->projector(new RouteContextPlan(detaillessResources: ['audit']))->routeContext('tenant'));

        $this->assertArrayHasKey('audit.edit', $with);
        $this->assertArrayNotHasKey('audit.edit', $without);
        $this->assertArrayHasKey('audit.index', $without);
        $this->assertArrayHasKey('circuits.edit', $without);
    }

    public function test_a_shell_segment_joins_the_href_only_in_a_scoped_realm(): void
    {
        $plan = new RouteContextPlan(
            shelledResources: [
                'fragments' => ['shell' => 'knowledge', 'path' => 'fragments'],
                'tenants' => ['shell' => 'knowledge', 'path' => 'tenants'],
            ],
            shellBases: ['app' => '', 'knowledge' => 'knowledge'],
        );

        $projector = $this->projector($plan);

        // tenant: routeBase `/`, non-central ⇒ the shell contributes its segment.
        $this->assertSame('/knowledge/fragments', $projector->hrefs('tenant')['fragments.index']);
        // operator: routeBase `/operator`, central ⇒ registers no shell registry, so the leaf is FLAT
        // whatever it declares. Same plan, same shell, different join — which is the whole point of
        // asserting it on two realms rather than one.
        $this->assertSame('/operator/tenants', $projector->hrefs('operator')['tenants.index']);
    }

    public function test_the_contributor_declines_a_realm_this_host_does_not_have(): void
    {
        $this->assertNull($this->app->make(FrameNavContribution::class)->contributeNav('no-such-realm'));
    }

    public function test_a_realm_with_no_registered_navigation_still_gets_its_router_table(): void
    {
        $block = $this->app->make(FrameNavContribution::class)->contributeNav('tenant');

        $this->assertNotNull($block, 'Declining here would throw away the half that works — routeContext needs no navigation to exist.');
        $this->assertSame([], $block['nav']['items']);
        $this->assertNotEmpty($block['routeContext']);
    }

    public function test_the_package_binds_the_plug_so_a_host_writes_no_controller(): void
    {
        $this->assertInstanceOf(FrameNavContribution::class, $this->app->make(FrameNavContributor::class));
    }

    public function test_the_plan_defaults_empty_because_every_list_in_it_is_host_ia(): void
    {
        $plan = $this->app->make(RouteContextPlan::class);

        $this->assertSame([], $plan->shelledResources);
        $this->assertSame([], $plan->centralStandalone);
        $this->assertSame(['app' => ''], $plan->shellBases);
    }

    public function test_a_published_config_block_is_read_as_the_host_list(): void
    {
        config(['beam.ux.frame_nav.route_context' => [
            'resource_paths' => ['audit' => 'history'],
            'folded_resources' => ['invitations'],
        ]]);

        $byName = $this->byName($this->app->make(RouteContextProjector::class)->routeContext('tenant'));

        $this->assertSame('history', $byName['audit.index']->path);
        $this->assertArrayNotHasKey('invitations.index', $byName);
    }

    private function projector(?RouteContextPlan $plan = null): RouteContextProjector
    {
        return new RouteContextProjector(
            $this->app->make(ResourceRegistry::class),
            $this->app->make(\Splicewire\Beam\Particle\ParticleResourceRegistry::class),
            $this->app->make(\Splicewire\Beam\Realm\RealmRegistry::class),
            $plan ?? RouteContextPlan::empty(),
        );
    }

    /**
     * @param  array<int, \Schemastud\Frame\Registry\RouteContextEntry>  $entries
     * @return array<int, string>
     */
    private function routeNames(array $entries): array
    {
        return array_map(fn ($entry): string => $entry->routeName, $entries);
    }

    /**
     * @param  array<int, \Schemastud\Frame\Registry\RouteContextEntry>  $entries
     * @return array<string, \Schemastud\Frame\Registry\RouteContextEntry>
     */
    private function byName(array $entries): array
    {
        $byName = [];

        foreach ($entries as $entry) {
            $byName[$entry->routeName] = $entry;
        }

        return $byName;
    }
}

/**
 * A minimal Data class for the fixture resources — `ParticleResource::$data` names the shape a
 * resource projects, and frame reflects it when building the context block.
 */
class FixtureResourceData extends \Spatie\LaravelData\Data
{
    public function __construct(public string $id = '') {}
}
