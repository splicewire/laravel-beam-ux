<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Http\Controllers\PublicEntryController;

/**
 * api-surface-coherence ticket 134 (ruled by 142): a catch-all reservation is a HOST-SIDE LIST, and the
 * lists must COMPOSE. Before this, `beam.ux.site.reserved_prefixes` was read with `??=` — a host that
 * reserved `mcp` silently UN-reserved `api`, and Laravel's `mergeConfigFrom` is shallow, so a published
 * host config carrying only `site.reserved_prefixes` replaced the package's whole `site` array. The
 * measured failure on the flagship was never the list; it was that two lists existed and editing one
 * removed the other. The macro now unions the package baseline, the host config and its own argument.
 *
 * The routes are registered AFTER the macro mounts, deliberately — that is the real shape (stancl/tenancy
 * groups `routes/tenant.php` inside `booted()`), and a test that registered them first would pass on the
 * unfixed code.
 */
class ReservedPrefixCompositionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('beam_ux_entries');
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
    }

    public function test_a_host_list_composes_with_the_package_baseline_rather_than_replacing_it(): void
    {
        config(['beam.ux.site.reserved_prefixes' => ['mcp', 'mcp-local']]);
        Route::beamUxSite('site/entry');

        Route::get('api/v1/lineage', fn () => response()->json(['route' => 'api']));
        Route::get('mcp', fn () => response()->json(['route' => 'mcp']));
        Route::get('mcp-local', fn () => response()->json(['route' => 'mcp-local']));

        // The host's additions reach their own routes …
        $this->get('/mcp')->assertOk()->assertJson(['route' => 'mcp']);
        $this->get('/mcp-local')->assertOk()->assertJson(['route' => 'mcp-local']);

        // … and the package's `api` baseline survives the host having written a list at all. This is
        // the assertion that was red: `??=` let `['mcp', 'mcp-local']` REPLACE `['api']`.
        $this->get('/api/v1/lineage')->assertOk()->assertJson(['route' => 'api']);
    }

    public function test_the_macro_argument_composes_with_the_config_and_the_baseline(): void
    {
        config(['beam.ux.site.reserved_prefixes' => ['reports']]);
        Route::beamUxSite('site/entry', reservedPrefixes: ['mcp']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $show = Route::getRoutes()->getByName('beam.ux.site.show');

        // One constraint carrying all three sources, baseline first, de-duplicated.
        $this->assertSame(
            PublicEntryController::pathConstraint(['api', 'reports', 'mcp']),
            $show->wheres['path'],
        );
    }

    public function test_a_host_cannot_unreserve_the_baseline_with_an_empty_list(): void
    {
        // `api` is beam's fleet-wide API boundary (ADR-0211 §7). An empty host list is "I add nothing",
        // not "serve /api from entries" — the earlier docblock promised the latter and nothing consumed it.
        config(['beam.ux.site.reserved_prefixes' => []]);
        Route::beamUxSite('site/entry');

        Route::get('api/v1/lineage', fn () => response()->json(['route' => 'api']));

        $this->get('/api/v1/lineage')->assertOk()->assertJson(['route' => 'api']);
    }
}
