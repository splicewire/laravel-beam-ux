<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Bindings\CollectionSource;
use Splicewire\Beam\Ux\Bindings\ManifestCollectionSource;
use Splicewire\Beam\Ux\Bindings\PublishedBindingDocument;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/** Exercise the actual page response and canonical resource read, not an author-side query surrogate. */
class BindingRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        \Illuminate\Support\Facades\Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return new \Illuminate\Http\JsonResponse(['page' => $page, 'props' => $props]);
            }
        });
        \Illuminate\Support\Facades\Route::beamUxSite('site/entry');
        Schema::create('binding_records', function (Blueprint $table) {
            $table->id();
            $table->string('tenant');
            $table->boolean('public');
            $table->string('title');
            $table->string('secret');
        });
        BindingRecord::insert([
            ['id' => 1, 'tenant' => 'a', 'public' => true, 'title' => 'Visible A', 'secret' => 'private attribute'],
            ['id' => 2, 'tenant' => 'a', 'public' => false, 'title' => 'Author only', 'secret' => 'private record'],
            ['id' => 3, 'tenant' => 'b', 'public' => true, 'title' => 'Visible B', 'secret' => 'other tenant'],
        ]);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'binding-records', backing: BindingRecord::class, data: BindingPrivateData::class,
            publicRead: BindingPublicData::class,
            scope: fn ($query) => $query->where('tenant', config('binding-test.tenant', 'a')),
            publicScope: fn ($query) => $query->where('public', true),
            readOnly: true,
        ));
        $this->app->instance(ManifestCollectionSource::class, new class implements ManifestCollectionSource {
            public function collections(BeamUxEntry $entry, string $profile): array
            {
                return [new CollectionSource('articles', 'binding-records')];
            }
        });
        // Snapshot retrieval is tested separately. This fixture supplies the published declaration;
        // resource authorization, projection, database scopes and HTTP rendering remain real.
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument {
            public function read(BeamUxEntry $entry): array
            {
                return [[
                    'kind' => 'block', 'id' => 'repeat-one', 'name' => 'article',
                    'repeat' => ['collection' => 'articles', 'query' => ['limit' => 10]],
                    'children' => [['kind' => 'text', 'value' => 'placeholder', 'field' => 'title']],
                ]];
            }
        });
        $root = BeamUxEntry::rootFor();
        BeamUxEntry::create(['slug' => 'bound', 'segment' => 'bound', 'type' => UxType::Page, 'parent_id' => $root->id]);
    }

    public function test_guest_render_never_contains_the_authors_private_record_or_private_fields(): void
    {
        $response = $this->get('/bound')->assertOk();
        $this->assertSame([['title' => 'Visible A', 'url' => null]], $response->json('props.bindings.repeat-one.records'));
        $this->assertStringNotContainsString('Author only', $response->getContent());
        $this->assertStringNotContainsString('private attribute', $response->getContent());
    }

    public function test_bound_render_does_not_reuse_another_tenants_records(): void
    {
        config(['binding-test.tenant' => 'a']);
        $this->assertSame('Visible A', $this->get('/bound')->assertOk()->json('props.bindings.repeat-one.records.0.title'));
        config(['binding-test.tenant' => 'b']);
        $response = $this->get('/bound')->assertOk();
        $this->assertSame('Visible B', $response->json('props.bindings.repeat-one.records.0.title'));
        $this->assertStringNotContainsString('Visible A', $response->getContent());
    }

    public function test_binding_response_is_private_and_no_store_even_for_a_public_page(): void
    {
        $response = $this->get('/bound')->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_browser_query_cannot_replace_the_saved_query_or_profile_source(): void
    {
        $response = $this->get('/bound?collection=private&filter[secret]=private+record&sort=-secret')->assertOk();
        $this->assertSame([['title' => 'Visible A', 'url' => null]], $response->json('props.bindings.repeat-one.records'));
    }

    public function test_an_undeclared_source_refuses_instead_of_enumerating_resources(): void
    {
        $this->app->forgetInstance(ManifestCollectionSource::class);
        $this->getJson('/bound')->assertUnprocessable();
    }

    public function test_no_public_read_returns_the_same_empty_shape_as_no_records(): void
    {
        app(ParticleResourceRegistry::class)->get('binding-records')->publicRead = null;
        $this->assertSame([], $this->get('/bound')->assertOk()->json('props.bindings.repeat-one.records'));
    }

    public function test_binding_document_reads_only_the_named_publication_snapshot(): void
    {
        $entry = new BeamUxEntry;
        $entry->forceFill(['particle_id' => 'particle', 'published_version' => 'published']);
        $particle = new \Splicewire\Beam\Models\BeamParticle;
        $entry->setRelation('particle', $particle);
        $version = new \Rushing\Versioning\Models\Version;
        $version->forceFill(['snapshot' => ['payload' => [['kind' => 'text', 'value' => 'published']]]]);
        $store = \Mockery::mock(\Rushing\Versioning\Contracts\VersionStore::class);
        $store->shouldReceive('version')->once()->with($particle, 'published')->andReturn($version);
        $store->shouldNotReceive('head');
        $store->shouldNotReceive('snapshot');
        $this->app->instance(\Rushing\Versioning\Contracts\VersionStore::class, $store);
        $this->assertSame([['kind' => 'text', 'value' => 'published']], (new PublishedBindingDocument)->read($entry));
        $entry->published_version = null;
        $this->assertSame([], (new PublishedBindingDocument)->read($entry));
    }

    public function test_two_collection_addresses_in_one_render_keep_their_own_public_scope(): void
    {
        $this->installCollectionFixtures();
        $response = $this->get('/bound')->assertOk();
        $this->assertSame([['fields' => ['title' => 'articles public'], 'url' => null]], $response->json('props.bindings.articles.records'));
        $this->assertSame([['fields' => ['title' => 'events public'], 'url' => null]], $response->json('props.bindings.events.records'));
        $this->assertStringNotContainsString('author only', $response->getContent());
        $this->assertStringNotContainsString('private field', $response->getContent());
    }

    public function test_hidden_collection_filter_refuses_and_the_next_render_has_no_stale_address(): void
    {
        $this->installCollectionFixtures();
        $documents = app(PublishedBindingDocument::class);
        foreach ([['filter' => ['secret' => 'private field']], ['sort' => 'secret'], ['sort' => '-secret']] as $query) {
            $documents->savedQuery = $query;
            $this->getJson('/bound')->assertUnprocessable();
            $documents->savedQuery = [];
            $response = $this->getJson('/bound')->assertOk();
            $this->assertSame('articles public', $response->json('props.bindings.articles.records.0.fields.title'));
            $this->assertSame('events public', $response->json('props.bindings.events.records.0.fields.title'));
        }
    }

    private function installCollectionFixtures(): void
    {
        Schema::create(\Splicewire\Beam\Facades\Beam::table('schemas'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name');
            $table->integer('version');
            $table->string('fingerprint');
            $table->json('artifact');
            $table->timestamps();
        });
        Schema::create(\Splicewire\Beam\Facades\Beam::table('particles'), function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('schema_ref');
            $table->string('schema_id');
            $table->string('source_tier')->default('local');
            $table->string('migration_status')->default('current');
            $table->json('payload');
            $table->string('head_version')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        $registry = new \Splicewire\Beam\Schema\DatabaseSchemaRegistry;
        foreach (['articles', 'events'] as $name) {
            $stem = 'https://example.test/'.$name;
            $registry->register([
                '$id' => $stem.'/1', 'type' => 'object',
                'x-beam-collection' => ['label' => $name, 'nameField' => 'title'],
                'properties' => ['title' => ['type' => 'string'], 'visible' => ['type' => 'boolean'], 'secret' => [
                    'type' => 'string', 'x-filter' => ['name' => 'secret', 'operator' => 'exact', 'control' => 'text'],
                    'x-sort' => ['name' => 'secret'],
                ]],
                'x-beam-public-read' => ['fields' => ['title'], 'where' => ['all' => [['field' => 'visible', 'operator' => 'equals', 'value' => true]]]],
            ]);
            foreach ([true, false] as $visible) {
                \Illuminate\Support\Facades\DB::table(\Splicewire\Beam\Facades\Beam::table('particles'))->insert([
                    'id' => $name.'-'.(int) $visible, 'schema_ref' => $stem.'/1', 'schema_id' => $stem.'/1',
                    'payload' => json_encode(['title' => $name.($visible ? ' public' : ' author only'), 'visible' => $visible, 'secret' => 'private field']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $reconciler = \Mockery::mock(\Rushing\Versioning\Contracts\RecordReconciler::class);
        $reconciler->shouldReceive('currentId')->andReturnUsing(fn ($stem) => $stem.'/1');
        $reconciler->shouldReceive('reconcile')->andReturnUsing(fn ($payload, $id) => \Rushing\Versioning\MigrationOutcome::current($id));
        $this->app->instance(\Rushing\Versioning\Contracts\RecordReconciler::class, $reconciler);
        $this->app->instance(\Splicewire\Beam\Collections\CollectionSchemaResolver::class,
            new \Splicewire\Beam\Collections\CollectionSchemaResolver(new \Splicewire\Beam\Schema\RegistrySchemaTargetResolver($registry), $registry));
        $this->app->instance(ManifestCollectionSource::class, new class implements ManifestCollectionSource {
            public function collections(BeamUxEntry $entry, string $profile): array
            {
                return array_map(fn ($name) => new CollectionSource($name, 'records', ['collection' => 'https://example.test/'.$name]), ['articles', 'events']);
            }
        });
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument {
            public array $savedQuery = [];

            public function read(BeamUxEntry $entry): array
            {
                return array_map(fn ($name) => [
                    'kind' => 'block', 'id' => $name, 'name' => 'article',
                    'repeat' => ['collection' => $name, 'query' => $this->savedQuery],
                    'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title'], ['kind' => 'text', 'value' => '', 'field' => 'fields.secret']],
                ], ['articles', 'events']);
            }
        });
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
            $table->string('segment')->nullable()->index();
            $table->integer('nav_order')->nullable();
            $table->json('traverse')->nullable();
            $table->json('access')->nullable();
            $table->json('requirements')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            // ADR-0213's chrome columns — needed by the §7 entry-nesting cases below.
            $table->string('layout')->nullable();
            $table->string('template')->nullable();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
            $table->unique(['parent_id', 'segment']);
        });
    }
}

class BindingRecord extends Model
{
    protected $table = 'binding_records';
    protected $guarded = [];
    public $timestamps = false;
}

class BindingPublicData extends Data
{
    public function __construct(public string $title) {}
}

class BindingPrivateData extends Data
{
    public function __construct(public string $title, public string $secret) {}
}
