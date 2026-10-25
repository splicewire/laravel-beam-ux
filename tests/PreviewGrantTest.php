<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;
use Splicewire\Beam\Ux\Preview\PreviewGrantResolver;
use Splicewire\Beam\Ux\Preview\PreviewGrantService;
use Symfony\Component\HttpFoundation\Response;

class PreviewGrantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../database/migrations/shared/create_beam_ux_preview_grants_table.php.stub')->up();
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
    }

    public function test_a_grant_is_opaque_hashed_scoped_and_expires(): void
    {
        $service = app(PreviewGrantService::class);
        $grant = $service->issue(
            entryId: 'entry-a',
            versionId: 'version-a',
            releaseId: null,
            createdBy: 'actor-a',
            expiresAt: now()->addMinute(),
        );

        $this->assertSame(64, strlen($grant->token));
        $this->assertSame(hash('sha256', $grant->token), DB::table('beam_ux_preview_grants')->value('token_hash'));
        $this->assertNotSame($grant->token, DB::table('beam_ux_preview_grants')->value('token_hash'));
        $this->assertSame('entry-a', $service->resolve($grant->token)?->entry_id);
        $this->assertNull($service->resolve('not-a-real-grant'));

        $this->travel(61)->seconds();

        $this->assertNull($service->resolve($grant->token));
    }

    public function test_revocation_and_scope_binding_are_enforced(): void
    {
        DB::table('beam_ux_releases')->insert([
            'id' => 'release-a', 'title' => 'Preview', 'realm' => 'site', 'created_by' => 'actor-a',
            'edited_by' => 'actor-a', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('beam_ux_release_members')->insert([
            'id' => Str::uuid(), 'release_id' => 'release-a', 'entry_id' => 'entry-a',
            'version_id' => 'version-a', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(PreviewGrantService::class);
        $grant = $service->issue(
            entryId: 'entry-a',
            versionId: 'version-a',
            releaseId: 'release-a',
            createdBy: 'actor-a',
            expiresAt: now()->addMinute(),
        );

        $resolved = $service->resolve($grant->token);
        $this->assertSame('release-a', $resolved?->release_id);
        $this->assertSame('version-a', $resolved?->version_id);

        $service->revoke($resolved);

        $this->assertNull($service->resolve($grant->token));
    }

    public function test_a_release_grant_stops_resolving_after_the_release_publishes(): void
    {
        DB::table('beam_ux_releases')->insert([
            'id' => 'release-live', 'title' => 'Preview', 'realm' => 'site', 'created_by' => 'actor-a',
            'edited_by' => 'actor-a', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('beam_ux_release_members')->insert([
            'id' => Str::uuid(), 'release_id' => 'release-live', 'entry_id' => 'entry-a',
            'version_id' => 'version-a', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(PreviewGrantService::class);
        $grant = $service->issue('entry-a', 'version-a', 'release-live', 'actor-a', now()->addMinute());

        $this->assertNotNull($service->resolve($grant->token));
        DB::table('beam_ux_releases')->where('id', 'release-live')->update(['status' => 'published']);
        $this->assertNull($service->resolve($grant->token));
    }

    public function test_a_grant_does_not_resolve_on_another_tenant_connection(): void
    {
        DB::table('beam_ux_releases')->insert([
            'id' => 'release-shared-id', 'title' => 'Tenant A', 'realm' => 'site', 'created_by' => 'actor-a',
            'edited_by' => 'actor-a', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(PreviewGrantService::class);
        $grant = $service->issue(
            entryId: 'entry-shared-id',
            versionId: 'version-shared-id',
            releaseId: 'release-shared-id',
            createdBy: 'actor-a',
            expiresAt: now()->addMinute(),
        );

        config(['database.connections.preview_tenant' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'preview_tenant']);
        app('db')->purge('preview_tenant');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_preview_grants_table.php.stub')->up();
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
        DB::table('beam_ux_releases')->insert([
            'id' => 'release-shared-id', 'title' => 'Tenant B', 'realm' => 'site', 'created_by' => 'actor-b',
            'edited_by' => 'actor-b', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Even if the other tenant has the same entry/version/release identifiers, it has no grant row.
        $this->assertNull($service->resolve($grant->token));
    }

    public function test_only_a_resolved_grant_marks_the_request_and_forces_private_noindex_headers(): void
    {
        $grant = new BeamUxPreviewGrant;
        $grant->forceFill(['entry_id' => 'entry-a', 'version_id' => 'version-a']);
        $service = \Mockery::mock(PreviewGrantResolver::class);
        $service->shouldReceive('resolve')->once()->with('opaque-cookie-value')->andReturn($grant);

        $request = Request::create('/previewed-page');
        $request->cookies->set(ResolvePreviewContext::COOKIE, 'opaque-cookie-value');
        $response = (new ResolvePreviewContext($service))->handle($request, fn () => new Response('page'));

        $this->assertNotNull($request->attributes->get('beam.ux.preview'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
    }

    public function test_an_unsigned_preview_url_cannot_resolve_a_grant_or_set_the_preview_cookie(): void
    {
        Route::beamUxSite('site/entry');
        $this->assertSame('/_beam/preview/not-a-signed-link', route('beam.ux.site.preview.start', ['token' => 'not-a-signed-link'], false));
        $resolver = \Mockery::mock(PreviewGrantResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(PreviewGrantResolver::class, $resolver);

        $response = $this->get('/_beam/preview/not-a-signed-link');

        $response->assertNotFound();
        $this->assertStringNotContainsString('beam_ux_preview=', (string) $response->headers->get('Set-Cookie'));
    }
}
