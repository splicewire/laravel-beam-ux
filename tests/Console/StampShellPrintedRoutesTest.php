<?php

namespace Splicewire\Beam\Ux\Tests\Console;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Ux\Shell\ShellStamps;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * The route block `splicewire:beam:ux:stamp-shell` prints must work pasted AS PRINTED (make-surface 02 review,
 * finding 1). The site's `Route::beamUxSite()` registers a `{path}` catch-all; Laravel matches routes in registration
 * order and the catch-all reserves only `api` and `_beam`, so every route pasted after it is shadowed. The fresh-host
 * proof passed only because its `routes/web.php` moved the catch-all by hand.
 *
 * So these tests take the printed block out of the command's own output, register it unchanged as a host's
 * `routes/web.php` would (one file, `use Illuminate\Support\Facades\Route;`), and ask the router which route each
 * request reaches.
 */
class StampShellPrintedRoutesTest extends TestCase
{
    private string $host;

    protected function getPackageProviders($app): array
    {
        // A host has Inertia's provider (the printed `Route::inertia` lines) and schemastud/frame (the operator console).
        return [...parent::getPackageProviders($app), FrameServiceProvider::class, \Inertia\ServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = sys_get_temp_dir().'/beam-shell-routes-'.Str::random(10);
        File::ensureDirectoryExists($this->host.'/routes');
        File::put($this->host.'/composer.json', '{"require": {}}');
        $this->app->setBasePath($this->host);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    public function test_the_printed_block_pasted_unchanged_routes_account_and_operator_to_their_own_actions(): void
    {
        $block = $this->printedBlock('site,account,operator');
        $this->register($block);

        $this->assertSame('home', $this->reach('/'));
        $this->assertSame('account', $this->reach('/account'), "the printed block:\n".implode("\n", $block));
        $this->assertSame('operator.home', $this->reach('/operator'));
        $this->assertSame('operator.frame.console', $this->reach('/operator/dashboard'));
        // The catch-all still renders every other public path.
        $this->assertSame('beam.ux.site.show', $this->reach('/about-us'));
    }

    public function test_the_four_surface_block_prints_the_ruled_mount_calls_in_order(): void
    {
        $block = $this->printedBlock('site,account,operator,os');
        $this->assertSame([
            "Route::beamUxHome('site/home');",
            "Route::inertia('account', 'account/home')->middleware(['auth', 'verified'])->name('account')->defaults(\\Splicewire\\Beam\\Authorization\\SeatGate::OPEN_TO_MEMBERS, true);",
            "Route::beamRealmConsole('tenant', ['auth', 'verified']);",
            "Route::beamRealmConsole('operator', ['auth', 'verified']);",
            "Route::inertia('os', 'os')->middleware(['auth', 'verified', 'can:entitlement:os.enter'])->name('os.shell');",
            "Route::beamUxSite('site/entry');",
        ], $this->routeLines($block));
        $this->register($block);
        $this->assertSame('home', $this->reach('/'));
        $this->assertSame('frame.console', $this->reach('/dashboard'));
    }

    public function test_the_site_catch_all_is_the_last_printed_line_and_tier_lines_come_before_it(): void
    {
        $this->app->make(ShellStamps::class)->route('operator', 'Route::towerSpa();');

        $lines = $this->routeLines($this->printedBlock('site,account,operator'));

        $this->assertSame("Route::beamUxSite('site/entry');", end($lines));
        $this->assertLessThan(array_search("Route::beamUxSite('site/entry');", $lines, true), array_search('Route::towerSpa();', $lines, true));
    }

    public function test_a_site_only_block_prints_its_home_before_its_catch_all(): void
    {
        $block = $this->printedBlock('site');
        $this->register($block);

        $this->assertSame(["Route::beamUxHome('site/home');", "Route::beamUxSite('site/entry');"], $this->routeLines($block));
        $this->assertSame('home', $this->reach('/'));
        $this->assertSame('beam.ux.site.show', $this->reach('/about-us'));
    }

    public function test_a_block_without_the_site_has_no_catch_all(): void
    {
        $block = $this->printedBlock('account,operator');
        $this->register($block);

        $this->assertStringNotContainsString('beamUxSite', implode("\n", $block));
        $this->assertSame('account', $this->reach('/account'));
        $this->assertSame('operator.home', $this->reach('/operator'));
    }

    /**
     * The block exactly as printed: the indented lines under the "Mount it" heading, the 4-space indent removed.
     *
     * @return list<string>
     */
    private function printedBlock(string $surfaces): array
    {
        $this->assertSame(0, Artisan::call('splicewire:beam:ux:stamp-shell', ['--surfaces' => $surfaces]));
        $output = preg_split('/\R/', Artisan::output());
        $start = null;

        foreach ($output as $i => $line) {
            if (str_contains($line, 'Mount it')) {
                $start = $i + 1;

                break;
            }
        }

        $this->assertNotNull($start, 'no route block printed');

        while ($start < count($output) && trim($output[$start]) === '') {
            $start++;
        }

        $block = [];

        for ($i = $start; $i < count($output) && str_starts_with($output[$i], '    '); $i++) {
            $block[] = substr($output[$i], 4);
        }

        return $block;
    }

    /** @param list<string> $block @return list<string> */
    private function routeLines(array $block): array
    {
        return array_values(array_filter($block, fn (string $line) => str_starts_with($line, 'Route::')));
    }

    /** Register the block as the host's route file — the lines unchanged, in printed order. @param list<string> $block */
    private function register(array $block): void
    {
        $file = $this->host.'/routes/web.php';
        File::put($file, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n".implode("\n", $block)."\n");

        require $file;

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function reach(string $uri): ?string
    {
        return $this->app['router']->getRoutes()->match(Request::create($uri, 'GET'))->getName();
    }
}
