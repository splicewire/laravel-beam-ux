<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\PageEntryRefData;
use Splicewire\Beam\Ux\Data\PageEntryRefInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\ResolvePageEntryRefOp;
use Splicewire\Beam\Ux\Support\EditorRoutes;
use Splicewire\Beam\Ux\Support\EntryBody;

class EditorKitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        Storage::fake('mirror');
        Storage::fake('artifacts');
        config(['beam.ux.namespace' => 'test-kit', 'beam.ux.storage.mirror_disk' => 'mirror', 'beam.ux.compile.disk' => 'artifacts']);
        Gate::define('entitlement:ux.author', fn (?User $user = null) => $user instanceof KitAuthor);
        EditorRoutes::mount();
    }

    public function test_editor_routes_mount_the_version_preview_operation(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains('beam-ux-entries/{id}/preview', $routes);
    }

    public function test_signed_tenant_preview_opener_is_opt_in_and_site_host_scoped(): void
    {
        \Illuminate\Support\Facades\Route::domain('{tenant}.site.example.test')->group(function (): void {
            \Illuminate\Support\Facades\Route::beamUxSite(
                'site/entry',
                routeName: 'beam.ux.test.',
                withTenantPreviewOpen: true,
            );
        });
        \Illuminate\Support\Facades\Route::beamUxSite('site/entry', routeName: 'beam.ux.central-test.');

        $routes = collect(app('router')->getRoutes()->getRoutes());
        $tenant = $routes->first(fn ($route) => $route->getName() === 'beam.ux.test.site.preview.tenant-open');

        $this->assertNotNull($tenant);
        $this->assertSame('{tenant}.site.example.test', $tenant->getDomain());
        $this->assertContains('signed', $tenant->gatherMiddleware());
        $this->assertNull($routes->first(fn ($route) => $route->getName() === 'beam.ux.central-test.site.preview.tenant-open'));
        $this->assertNotNull($routes->first(fn ($route) => $route->getName() === 'beam.ux.central-test.site.preview.open'));
    }

    public function test_tenant_preview_open_signature_expires_and_binds_the_release_path(): void
    {
        \Illuminate\Support\Facades\Route::domain('{tenant}.site.example.test')->group(function (): void {
            \Illuminate\Support\Facades\Route::beamUxSite(
                'site/entry',
                routeName: 'beam.ux.signed-test.',
                withTenantPreviewOpen: true,
            );
        });

        $release = 'release-123';
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'beam.ux.signed-test.site.preview.tenant-open',
            now()->addSeconds(60),
            [
                'tenant' => 'acme',
                'release' => $release,
                'actor' => 'tenant-model:tenant-a:actor-class:tenant-connection:42',
                'nonce' => str_repeat('a', 64),
            ],
        );

        $this->assertTrue(\Illuminate\Support\Facades\URL::hasValidSignature(\Illuminate\Http\Request::create($url)));
        $tampered = str_replace($release, 'release-456', $url);
        $this->assertFalse(\Illuminate\Support\Facades\URL::hasValidSignature(\Illuminate\Http\Request::create($tampered)));

        $this->travel(61)->seconds();
        $this->assertFalse(\Illuminate\Support\Facades\URL::hasValidSignature(\Illuminate\Http\Request::create($url)));
        $this->travelBack();
    }

    public function test_editor_routes_can_be_mounted_for_a_second_realm_with_distinct_route_names(): void
    {
        app(\Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery::class)
            ->registerClass(\Splicewire\Beam\Ux\Particle\ReleasePreviewOpenOp::class);
        EditorRoutes::mount('tenant/beam-ux-entries', 'tenant/beam-ux-releases', [
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);

        $routes = app('router')->getRoutes();
        $allRoutes = collect($routes->getRoutes());
        $central = $allRoutes->first(fn ($route) => $route->getName() === 'beam-ux-entry.body');
        $tenant = $allRoutes->first(fn ($route) => $route->getName() === 'tenant.beam-ux-entry.body');
        $centralReleaseIndex = $allRoutes->first(fn ($route) => $route->getName() === 'beam-ux-release.index');
        $tenantReleaseIndex = $allRoutes->first(fn ($route) => $route->getName() === 'tenant.beam-ux-release.index');
        $tenantReleaseShow = $allRoutes->first(fn ($route) => $route->getName() === 'tenant.beam-ux-release.show');
        $tenantRelease = $allRoutes->first(fn ($route) => $route->getName() === 'tenant.beam-ux-release.command');
        $tenantPreviewOpen = $allRoutes->first(fn ($route) => $route->getName() === 'tenant.beam-ux-release.preview-open');
        $centralPreviewOpen = $allRoutes->first(fn ($route) => $route->getName() === 'beam-ux-release.preview-open');
        $names = $allRoutes->map(fn ($route) => $route->getName())->filter()->values()->all();
        $this->assertNotNull($central, 'Registered route names: '.implode(', ', $names));
        $this->assertNotNull($tenant, 'Registered route names: '.implode(', ', $names));
        $this->assertNotNull($tenantReleaseIndex, 'Registered route names: '.implode(', ', $names));
        $this->assertNotNull($tenantReleaseShow, 'Registered route names: '.implode(', ', $names));
        $this->assertNotNull($tenantRelease, 'Registered route names: '.implode(', ', $names));
        $this->assertNotNull($tenantPreviewOpen, 'The tenant mount alone exposes the short-lived Preview handoff.');
        $this->assertSame('tenant/beam-ux-releases/{id}/preview-open', $tenantPreviewOpen->uri());
        $this->assertNotNull($tenantPreviewOpen->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM] ?? null);
        $this->assertSame('tenant', $tenantPreviewOpen->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM]);
        $this->assertSame(
            ['ux.author' => 'site.edit'],
            $tenantPreviewOpen->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::ABILITY_OVERRIDES],
        );
        $this->assertNull($centralPreviewOpen, 'Central continues to use its existing signed-in Preview route.');
        $this->assertSame('beam-ux-entries/{id}/body', $central->uri());
        $this->assertSame('tenant/beam-ux-entries/{id}/body', $tenant->uri());
        $this->assertSame('tenant', $tenant->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM]);
        $this->assertSame(
            ['ux.author' => 'site.edit'],
            $tenant->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::ABILITY_OVERRIDES],
        );
        $this->assertArrayNotHasKey(\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM, $central->defaults);
        $this->assertArrayNotHasKey(\Splicewire\Beam\Http\Particle\ParticleOperationController::ABILITY_OVERRIDES, $central->defaults);
        $this->assertSame('tenant', $tenantRelease->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM]);
        $this->assertSame('tenant', $tenantReleaseIndex->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM]);
        $this->assertSame(
            ['ux.author' => 'site.edit'],
            $tenantReleaseIndex->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::ABILITY_OVERRIDES],
        );
        $this->assertSame(
            ['ux.author' => 'site.edit'],
            $tenantReleaseShow->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::ABILITY_OVERRIDES],
        );
        $this->assertNotNull($centralReleaseIndex, 'Central routes remain mounted beside the tenant copy.');
        $this->assertArrayNotHasKey(\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM, $centralReleaseIndex->defaults);
        $this->assertArrayNotHasKey(\Splicewire\Beam\Http\Particle\ParticleOperationController::ABILITY_OVERRIDES, $centralReleaseIndex->defaults);
    }

    public function test_a_realm_mount_can_leave_the_release_files_recording_unmounted(): void
    {
        EditorRoutes::mount('tenant/beam-ux-entries', 'tenant/beam-ux-releases', [
            'realm' => 'tenant',
        ]);

        $allRoutes = collect(app('router')->getRoutes()->getRoutes());
        $names = $allRoutes
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->all();

        $this->assertContains('tenant.beam-ux-entry.body', $names);
        $this->assertContains('tenant.beam-ux-release.command', $names);
        $this->assertNotContains('tenant.beam-ux-release-files.index', $names);
        $this->assertNotContains('tenant.beam-ux-release-files.commit-now', $names);
        $centralFiles = $allRoutes->first(fn ($route) => $route->getName() === 'beam-ux-release-files.index');
        $this->assertNotNull($centralFiles, 'A tenant realm mount must not replace the central recording route.');
        $this->assertArrayNotHasKey(\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM, $centralFiles->defaults);
    }

    public function test_creation_requires_author_and_creates_the_default_body_once(): void
    {
        $body = [['kind' => 'block', 'name' => 'h1', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Default page']]]];
        $this->postJson('/beam-ux-entries/make-editable', ['slug' => 'home', 'body' => $body])->assertForbidden();
        $this->assertSame(0, BeamUxEntry::query()->count());
        $this->actingAs(new KitAuthor);
        $response = $this->postJson('/beam-ux-entries/make-editable', ['slug' => 'home', 'body' => $body])->assertOk();
        $entry = BeamUxEntry::query()->where('slug', 'home')->firstOrFail();
        $this->assertSame('test-kit', $entry->namespace);
        $this->assertSame((string) $entry->id, $response->json('data.id'));
        $this->assertNotNull($entry->particle_id);
        $this->assertNotNull($entry->published_version);
        $this->assertSame($body, app(EntryBody::class)->forSlug('home'));
        $this->postJson('/beam-ux-entries/make-editable', ['slug' => 'home', 'body' => []])->assertConflict();
        $this->assertSame($body, app(EntryBody::class)->forSlug('home'));
    }

    public function test_author_can_create_the_packaged_demo_islands(): void
    {
        $this->actingAs(new KitAuthor);
        $block = fn (string $name) => ['kind' => 'block', 'name' => $name, 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []];
        $body = [$block('DemoHero'), $block('DemoFeatureRow')];
        $this->postJson('/beam-ux-entries/make-editable', ['slug' => 'demo', 'body' => $body])->assertOk();
        $entry = BeamUxEntry::query()->where('slug', 'demo')->firstOrFail();
        $this->assertNotNull($entry->published_version);
        $this->assertSame($body, app(EntryBody::class)->forSlug('demo'));
    }

    public function test_invalid_slug_is_refused_without_creating_a_row(): void
    {
        $this->actingAs(new KitAuthor);
        $this->postJson('/beam-ux-entries/make-editable', ['slug' => '../escape', 'body' => []])->assertUnprocessable();
        $this->assertSame(0, BeamUxEntry::query()->count());
    }

    public function test_author_resolves_an_existing_page_ref_by_slug_without_writing(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'home',
            'type' => 'page',
            'format' => 'tsx',
            'namespace' => 'test-kit',
        ]);
        $before = $entry->refresh()->getRawOriginal();

        $this->actingAs(new KitAuthor);

        $this->getJson('/beam-ux-entries/resolve-ref?slug=home')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => (string) $entry->id,
                'slug' => 'home',
                'format' => 'tsx',
                'artifact' => null,
            ]]);

        $this->assertSame($before, $entry->refresh()->getRawOriginal());
        $this->assertSame(1, BeamUxEntry::query()->count());
    }

    public function test_unknown_slug_returns_json_null_without_creating_a_row(): void
    {
        $this->actingAs(new KitAuthor);

        $this->getJson('/beam-ux-entries/resolve-ref?slug=missing')
            ->assertOk()
            ->assertContent('null');

        $this->assertSame(0, BeamUxEntry::query()->count());
    }

    public function test_page_ref_resolution_refuses_guests_and_non_authors(): void
    {
        BeamUxEntry::create([
            'slug' => 'home',
            'type' => 'page',
            'namespace' => 'test-kit',
        ]);

        $this->getJson('/beam-ux-entries/resolve-ref?slug=home')->assertForbidden();

        $this->actingAs(new User);
        $this->getJson('/beam-ux-entries/resolve-ref?slug=home')->assertForbidden();
    }

    public function test_page_ref_resolution_is_a_subject_free_read_and_has_no_write_route(): void
    {
        $operation = (new ReflectionClass(ResolvePageEntryRefOp::class))
            ->getAttributes(ParticleOp::class)[0]
            ->newInstance();

        $this->assertSame(OperationKind::Read, $operation->kind);
        $this->assertSame(HttpMethod::Get, $operation->method);
        $this->assertSame(NoSubject::class, $operation->subject);
        $this->assertSame('ux.author', $operation->ability);
        $this->assertFalse($operation->abilityModel);
        $this->assertSame(PageEntryRefInputData::class, $operation->input);
        $this->assertSame(PageEntryRefData::class, $operation->output);

        $this->actingAs(new KitAuthor);
        $this->postJson('/beam-ux-entries/resolve-ref', ['slug' => 'home'])
            ->assertMethodNotAllowed();
        $this->assertSame(0, BeamUxEntry::query()->count());
    }

    public function test_packaged_theme_seeder_publishes_and_reuses_one_entry(): void
    {
        $this->seed(\Splicewire\Beam\Ux\Seeders\ThemeSeeder::class);
        $entry = BeamUxEntry::query()->where('namespace', 'theme')->firstOrFail();
        $this->assertNotNull($entry->published_version);
        $id = $entry->id;
        $particleId = $entry->particle_id;
        $this->seed(\Splicewire\Beam\Ux\Seeders\ThemeSeeder::class);
        $this->assertSame(1, BeamUxEntry::query()->where('namespace', 'theme')->count());
        $this->assertSame($particleId, BeamUxEntry::findOrFail($id)->particle_id);
    }

    public static function missingPublicationInputs(): array
    {
        return [['store', 'page'], ['pin', 'page'], ['store', 'theme'], ['pin', 'theme']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingPublicationInputs')]
    public function test_missing_publication_input_refuses_before_any_write(string $missing, string $kind): void
    {
        if ($missing === 'store') {
            Schema::drop(Beam::table('versions'));
        } else {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropColumn('published_version'));
        }
        $writes = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        try {
            if ($kind === 'theme') {
                $this->seed(\Splicewire\Beam\Ux\Seeders\ThemeSeeder::class);
            } else {
                \Splicewire\Beam\Ux\Particle\EntryMakeEditableOp::handle(null,
                    new \Illuminate\Http\Request(['slug' => 'home', 'body' => []]), new KitAuthor);
            }
            $this->fail('Missing publication input must refuse.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('needs the version store and the publication pin', $error->getMessage());
        }
        $this->assertSame([], $writes, 'Refuse before a write, not merely by rolling a transaction back.');
        $this->assertSame(0, BeamUxEntry::query()->count());
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        $this->runBeamWorkflowsMigrations();
    }
}
class KitAuthor extends User {}
