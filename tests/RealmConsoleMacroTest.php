<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Http\Controllers\FrameManifestController;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Data\Pages\RealmConsolePageData;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Route::beamRealmConsole()` is the starters' hand-written operator console mount, lifted.
 *
 * The first test is the lift's contract: the macro and a VERBATIM copy of the starter block
 * (`laravel-beam-commerce-starter` `routes/web.php`, the `operator.home` redirect and the operator frame
 * console group), each registered on its own router, describe the same routes: names, URIs, methods,
 * middleware, `where` constraints and defaults. The fixture gives the operator realm a projected leaf, a seat
 * inside its base and a seat outside it, so a macro that dropped either source, or stripped the base wrongly,
 * would produce a different constraint.
 */
class RealmConsoleMacroTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $seats = $this->app->make(NavSectionRegistry::class);

        foreach ([['people', '/operator/people'], ['calendars', '/calendars']] as [$key, $href]) {
            $seats->register(new NavSection(
                key: $key,
                realm: 'operator',
                label: ucfirst($key),
                icon: 'Users',
                href: $href,
                order: 40,
                entitlement: null,
                permission: null,
                audience: NavAudience::Product,
            ), by: self::class);
        }
    }

    public function test_the_macro_registers_what_the_starters_operator_block_registers(): void
    {
        $lifted = $this->router();
        $lifted->beamRealmConsole('operator', ['auth', 'verified']);

        $handWritten = $this->router();
        $this->starterOperatorBlock($handWritten);

        $this->assertSame($this->describe($handWritten), $this->describe($lifted));

        // And what that is, so an identical-but-empty pair cannot pass.
        $console = $this->named($lifted, 'operator.frame.console');
        $this->assertNotNull($console);
        $this->assertSame('operator/{frameRoute}', $console->uri());
        // beam-ux's own `authoring` and `ops` seats are declared for the operator realm too.
        $this->assertSame('(dashboard|authoring|calendars|people|ops)(\/[^\/]+)?', $console->wheres['frameRoute']);
        $this->assertSame(['auth', 'verified', 'can:entitlement:os.operate'], $console->middleware());
        $this->assertSame('operator', $this->named($lifted, 'operator.frame.manifest')->defaults['realm']);
        $this->assertSame(FrameManifestController::class, $this->named($lifted, 'operator.frame.manifest')->getActionName());
        $this->assertSame('operator', $this->named($lifted, 'operator.home')->uri());
    }

    public function test_a_second_realm_is_named_prefixed_and_gated_from_its_own_declaration(): void
    {
        $this->app->make(RealmRegistry::class)->register(new RealmDefinition(key: 'board', routeBase: '/board', guard: null, central: true));
        config(['beam.core.realms.board.gate' => ['entitlement' => 'board.view']]);
        $this->seat('board', 'seats', '/board/seats');

        $router = $this->router();
        $router->beamRealmConsole('board', ['auth']);

        $this->assertSame(
            [
                ['board.frame.console', 'board/{frameRoute}', ['auth', 'can:entitlement:board.view'], '(seats)(\/[^\/]+)?'],
                ['board.frame.manifest', 'board/frame/manifest', ['auth', 'can:entitlement:board.view'], null],
                ['board.home', 'board', ['auth', 'can:entitlement:board.view'], null],
            ],
            array_map(fn (array $r): array => [$r['name'], $r['uri'], $r['middleware'], $r['wheres']['frameRoute'] ?? null], $this->describe($router)),
        );
    }

    public function test_a_realm_that_gates_nothing_gets_no_can_middleware_and_a_host_can_skip_the_home(): void
    {
        $this->app->make(RealmRegistry::class)->register(new RealmDefinition(key: 'partner', routeBase: '/partner', guard: null, central: false));
        $this->seat('partner', 'deals', '/partner/deals');

        $router = $this->router();
        $router->beamRealmConsole('partner', ['auth'], home: false);

        $this->assertNull($this->named($router, 'partner.home'));
        $this->assertSame(['auth'], $this->named($router, 'partner.frame.console')->middleware());
    }

    public function test_a_projector_failure_mounts_the_manifest_and_no_console(): void
    {
        $this->app->bind(RouteContextProjector::class, fn () => throw new \RuntimeException('projector down'));

        $router = $this->router();
        $router->beamRealmConsole('operator', ['auth']);

        $this->assertNotNull($this->named($router, 'operator.frame.manifest'));
        $this->assertNull($this->named($router, 'operator.frame.console'));
    }

    public function test_a_root_realm_mounts_only_its_projected_console_without_props(): void
    {
        $this->seat('tenant', 'people', '/people');
        $router = $this->router();
        $router->beamRealmConsole('tenant', ['auth', 'verified']);
        $console = $this->named($router, 'frame.console');

        $this->assertNotNull($console);
        $this->assertSame('{frameRoute}', $console->uri());
        $this->assertSame(['auth', 'verified'], $console->middleware());
        $this->assertStringContainsString('people', $console->wheres['frameRoute']);
        $this->assertNull($this->named($router, 'tenant.home'));
        $this->assertNull($this->named($router, 'tenant.frame.manifest'));
        $this->assertCount(1, $router->getRoutes());
        $this->app->instance(EntryRenderer::class, new class implements EntryRenderer
        {
            public function render(string $page, array $props): Response
            {
                return response()->json(['page' => $page, 'props' => $props]);
            }
        });
        $response = ($console->getAction('uses'))();
        $this->assertSame(['page' => 'frame/console', 'props' => []], json_decode($response->getContent(), true));
    }

    public function test_the_site_realm_has_no_console(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route::beamRealmConsole('site'): the site realm's root");
        $this->router()->beamRealmConsole('site');
    }

    public function test_a_root_realm_cannot_claim_the_sites_home(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route::beamRealmConsole('tenant', home: true)");
        $this->router()->beamRealmConsole('tenant', home: true);
    }

    public function test_a_second_root_console_is_refused(): void
    {
        $router = $this->router();
        $this->seat('tenant', 'people', '/people');
        $router->beamRealmConsole('tenant');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a root console is already registered (frame.console)');
        $router->beamRealmConsole('tenant');
    }

    public function test_a_failed_root_projection_mounts_nothing(): void
    {
        $this->app->bind(RouteContextProjector::class, fn () => throw new \RuntimeException('projector down'));
        $router = $this->router();
        $router->beamRealmConsole('tenant');
        $this->assertCount(0, $router->getRoutes());
    }

    public function test_a_root_console_registered_after_the_site_is_refused_by_name(): void
    {
        $this->refuseConsoleAfterSite('tenant');
    }

    public function test_an_undomained_console_overlaps_an_earlier_domain_specific_site(): void
    {
        $router = $this->router();
        $router->domain('one.invalid')->group(fn () => $router->beamUxSite('site/entry', routeName: 'one.'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route::beamRealmConsole('tenant'): Route::beamUxSite() is already registered (one.site.show, '{path}')");
        $router->beamRealmConsole('tenant');
    }

    public function test_a_prefixed_console_registered_after_the_site_is_refused_by_name(): void
    {
        $this->refuseConsoleAfterSite('operator');
    }

    private function refuseConsoleAfterSite(string $realm): void
    {
        $router = $this->router();
        $router->beamUxSite('site/entry');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route::beamRealmConsole('{$realm}'): Route::beamUxSite() is already registered (beam.ux.site.show, '{path}')");
        $router->beamRealmConsole($realm);
    }

    public function test_the_home_redirects_to_the_dashboard_and_the_console_renders_the_three_props(): void
    {
        $renderer = new class implements EntryRenderer
        {
            /** @var array{0: string, 1: array<string, mixed>}|null */
            public ?array $rendered = null;

            public function render(string $page, array $props): Response
            {
                $this->rendered = [$page, $props];

                return new Response('console');
            }
        };
        $this->app->instance(EntryRenderer::class, $renderer);

        $operator = (new User)->forceFill(['id' => 1]);
        $member = (new User)->forceFill(['id' => 2]);
        Gate::define('entitlement:os.operate', fn ($user): bool => $user->getKey() === 1);

        Route::beamRealmConsole('operator', ['auth']);
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs($operator)->get('/operator')->assertRedirect('/operator/dashboard');
        $this->actingAs($operator)->get('/operator/people/7')->assertOk();
        $this->assertSame(['frame/console', ['realm' => 'operator', 'basename' => '/operator', 'manifestUrl' => '/operator/frame/manifest']], $renderer->rendered);
        $this->assertSame(array_keys(RealmConsolePageData::from($renderer->rendered[1])->toArray()), array_keys($renderer->rendered[1]));

        $this->actingAs($member)->get('/operator/dashboard')->assertForbidden();
        $this->actingAs($member)->get('/operator')->assertForbidden();
        $this->actingAs($operator)->get('/operator/unseated')->assertNotFound();
    }

    /**
     * The starter's operator mount, copied from `laravel-beam-commerce-starter` `routes/web.php` (the
     * `operator.home` route at :148-150, inside the `auth`+`verified` group, and the console group at :248-296)
     * with `Route::` spelled `$router->` and the host page Data swapped for this package's. Do not tidy it.
     */
    private function starterOperatorBlock(Router $router): void
    {
        $router->middleware(['auth', 'verified'])->group(function () use ($router): void {
            $router->get('operator', fn () => redirect()->to('/operator/'.\Splicewire\Beam\Dashboard\RealmDashboard::PATH))
                ->middleware('can:entitlement:os.operate')
                ->name('operator.home');
        });

        $router->middleware(['auth', 'verified', 'can:entitlement:os.operate'])
            ->prefix('operator')
            ->name('operator.frame.')
            ->group(function () use ($router): void {
                $router->get('frame/manifest', FrameManifestController::class)
                    ->defaults('realm', 'operator')
                    ->name('manifest');

                $hrefs = rescue(function (): array {
                    $paths = array_values(app(RouteContextProjector::class)->hrefs('operator'));

                    $sections = array_map(
                        fn (NavSection $seat): string => $seat->href,
                        app(NavSectionRegistry::class)->for('operator')
                    );

                    return array_filter([...$paths, ...$sections]);
                }, [], false);

                $segments = array_values(array_filter(array_unique(array_map(
                    function (string $href): string {
                        $relative = ltrim(\Illuminate\Support\Str::after($href, '/operator'), '/');

                        return explode('/', $relative)[0];
                    },
                    $hrefs
                ))));

                if ($segments !== []) {
                    $router->get('{frameRoute}', fn () => RealmConsolePageData::from([
                        'realm' => 'operator',
                        'basename' => '/operator',
                        'manifestUrl' => '/operator/frame/manifest',
                    ]))
                        ->where('frameRoute', '('.implode('|', array_map(fn (string $s): string => preg_quote($s, '/'), $segments)).')(\/[^\/]+)?')
                        ->name('console');
                }
            });
    }

    private function router(): Router
    {
        return new Router($this->app['events'], $this->app);
    }

    private function named(Router $router, string $name): ?RouteInstance
    {
        $router->getRoutes()->refreshNameLookups();

        return $router->getRoutes()->getByName($name);
    }

    private function seat(string $realm, string $key, string $href): void
    {
        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: $key,
            realm: $realm,
            label: ucfirst($key),
            icon: 'Users',
            href: $href,
            order: 10,
            entitlement: null,
            permission: null,
            audience: NavAudience::Product,
        ), by: self::class);
    }

    /**
     * Every route as the facts a host's route table carries, sorted by name. The action is left out on
     * purpose: the console and the home are closures in both, so only the manifest's controller is comparable,
     * and it is asserted separately.
     *
     * @return list<array{name: string|null, uri: string, methods: list<string>, middleware: list<string>, wheres: array<string, string>, defaults: array<string, mixed>}>
     */
    private function describe(Router $router): array
    {
        $rows = array_map(fn (RouteInstance $route): array => [
            'name' => $route->getName(),
            'uri' => $route->uri(),
            'methods' => $route->methods(),
            'middleware' => $route->middleware(),
            'wheres' => $route->wheres,
            'defaults' => $route->defaults,
        ], $router->getRoutes()->getRoutes());

        usort($rows, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $rows;
    }
}
