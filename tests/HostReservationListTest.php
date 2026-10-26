<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The package's own prefixes reach every reader of `beam.ux.site.reserved_prefixes`, not only beam-ux's catch-all.
 *
 * {@see ReservedPrefixCompositionTest} proves the renderer's catch-all composes `api` and `_beam` with the host's list. A
 * host's OWN catch-all reads the key directly: the flagship's SPA fallback (`routes/web.php`) excludes `api`, the key's
 * list, `docs` and the artifact root, and nothing else. A published host config carries its own `site` array, which
 * replaces the package's (`mergeConfigFrom` is shallow), so the key held only the host's prefixes, `_beam` was in no
 * list, and the SPA fallback swallowed `/_beam/preview/open/{release}` and `/_beam/preview/exit`: a Release Preview could
 * not be opened on the flagship's central host (EP-42 H2 receipt, 2026-10-26; EP-18).
 */
class HostReservationListTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // A published host config: its own `site` array, which the package's merge cannot reach into.
        $app['config']->set('beam.ux.site', ['reserved_prefixes' => ['mcp']]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('beam_ux_entries');
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
    }

    public function test_the_key_holds_the_package_s_own_prefixes_under_a_host_s_replacing_list(): void
    {
        $reserved = (array) config('beam.ux.site.reserved_prefixes');

        $this->assertContains('api', $reserved);
        $this->assertContains('_beam', $reserved);
        $this->assertContains('mcp', $reserved, 'the host\'s own prefix stays');
    }

    public function test_the_preview_routes_resolve_ahead_of_a_host_catch_all_built_from_the_key(): void
    {
        // The flagship's shape: its SPA fallback is registered before the renderer, from the key, and excludes what it lists.
        $exclusion = implode('|', array_map(
            fn (string $prefix): string => preg_quote($prefix, '/').'(?:$|\/)',
            (array) config('beam.ux.site.reserved_prefixes'),
        ));
        Route::get('/{any}', fn () => 'spa')->where('any', '^(?!'.$exclusion.').*$')->name('host.spa');
        Route::beamUxSite('site/entry');
        $this->app['router']->getRoutes()->refreshNameLookups();

        $match = fn (string $path) => $this->app['router']->getRoutes()->match(Request::create('http://localhost'.$path))->getName();

        $this->assertSame('beam.ux.site.preview.open', $match('/_beam/preview/open/01a17758-3f86-702e-a2b3-bbe521f0518e'));
        $this->assertSame('beam.ux.site.preview.exit', $match('/_beam/preview/exit'));
        $this->assertSame('host.spa', $match('/dashboard'), 'everything else is still the host\'s');
    }
}
