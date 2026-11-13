<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Ux\Particle\ReleasePreviewOpenOp;
use Splicewire\Beam\Ux\Routing\UxRoutes;
use Splicewire\Beam\Ux\Support\EditorRoutes;

/**
 * owner-review-20261112 13d (ADR-0243): beam-ux's editor routes also mount at the package root, beam/ux (the `beam`
 * tier), beside where the host mounts them today. The host's group is the boundary; legacy routes keep their URIs and
 * names until ticket 16.
 */
class UxRoutesTest extends TestCase
{
    public function test_the_default_root_registers_the_editor_surface_beside_the_legacy_mount(): void
    {
        Route::middleware('auth')->prefix('api')->group(fn () => EditorRoutes::mount());
        Route::domain('studio.example')->middleware('auth')->group(fn () => UxRoutes::mount());

        $legacy = $this->table('api/');
        $current = $this->table('beam/ux/');

        // The whole editor table, not a sample: entries' ops, releases and their files, redirects.
        $this->assertSame($legacy, $current);
        $this->assertGreaterThan(30, count($current));
        $this->assertNotEmpty(array_filter(array_keys($current), fn (string $key) => str_ends_with($key, ' beam-ux-entries/{id}/preview')));

        $body = app(Router::class)->getRoutes()->match(Request::create('http://studio.example/beam/ux/beam-ux-releases'));
        $this->assertContains('auth', $body->gatherMiddleware());
        $this->assertSame('studio.example', $body->getDomain());
        $this->assertStringStartsWith('beam.ux.', $body->getName());
        $this->assertSame(
            app(Router::class)->getRoutes()->match(Request::create('/api/beam-ux-releases'))->getActionName(),
            $body->getActionName(),
        );
    }

    public function test_a_realm_mount_passes_its_options_through_unchanged(): void
    {
        app(AttributedParticleDiscovery::class)->registerClass(ReleasePreviewOpenOp::class);
        $options = ['realm' => 'tenant', 'abilityOverrides' => ['ux.author' => 'site.edit'], 'includeReleaseFiles' => false, 'redirectsUri' => 'tenant/beam-ux-redirects'];

        Route::prefix('api')->group(fn () => EditorRoutes::mount('tenant/beam-ux-entries', 'tenant/beam-ux-releases', $options));
        UxRoutes::mount('tenant/beam-ux-entries', 'tenant/beam-ux-releases', $options);

        $this->assertSame($this->table('api/tenant/'), $this->table('beam/ux/tenant/'));
        $this->assertArrayNotHasKey('GET beam-ux-release-files', $this->table('beam/ux/'));
        $route = collect(app(Router::class)->getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === 'beam.ux.tenant.beam-ux-entry.body');
        $this->assertNotNull($route);
    }

    public function test_an_overridden_root_moves_only_the_package_mount(): void
    {
        Route::prefix('api')->group(fn () => EditorRoutes::mount());
        config(['beam.ux.api_root' => '/custom/ux/']);

        UxRoutes::mount();

        $this->assertSame($this->table('api/'), $this->table('custom/ux/'));
        $this->assertSame([], $this->table('beam/ux/'));
    }

    /** method + path-under-root => action, for every beam-ux route whose URI starts with $root; sorted. */
    private function table(string $root): array
    {
        $table = [];
        foreach (app(Router::class)->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $root) || ! str_contains($route->uri(), 'beam-ux-')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $table[$method.' '.substr($route->uri(), strlen($root))] = $route->getActionName();
            }
        }
        ksort($table);

        return $table;
    }
}
