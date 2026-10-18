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
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
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
        $this->app->instance(ManifestCollectionSource::class, new class implements ManifestCollectionSource
        {
            public function collections(BeamUxEntry $entry, string $profile): array
            {
                return [new CollectionSource('articles', 'binding-records')];
            }
        });
        // Snapshot retrieval is tested separately. This fixture supplies the published declaration;
        // resource authorization, projection, database scopes and HTTP rendering remain real.
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument
        {
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

    public function test_condition_fields_are_included_only_after_the_visitor_projection(): void
    {
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument
        {
            public function read(BeamUxEntry $entry): array
            {
                return [['kind' => 'block', 'id' => 'conditional', 'name' => 'article',
                    'repeat' => ['collection' => 'articles'],
                    'when' => ['all' => [['field' => 'title', 'operator' => 'is set'], ['field' => 'secret', 'operator' => 'is set']]],
                    'children' => []]];
            }
        });
        $this->getJson('/bound')->assertOk()->assertJsonPath('props.bindings.conditional.records', [['title' => 'Visible A', 'url' => null]]);
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

    public function test_a_denied_signed_in_reader_keeps_the_public_read_floor(): void
    {
        app(ParticleResourceRegistry::class)->get('binding-records')->readPolicy = BindingDeniedPolicy::class;
        $this->actingAs((new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 7]));
        $this->getJson('/bound')->assertOk()->assertJsonPath('props.bindings.repeat-one.records', [['title' => 'Visible A', 'url' => null]]);
    }

    public function test_public_floor_keeps_request_identity_tenant_and_no_public_read_refusal(): void
    {
        $resource = app(ParticleResourceRegistry::class)->get('binding-records');
        $resource->readPolicy = BindingDeniedPolicy::class;
        $visitor = (new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 7]);
        $this->actingAs($visitor);
        $resource->publicScope = function ($query) use ($visitor) {
            $this->assertSame($visitor, app(\Illuminate\Http\Request::class)->user());

            return $query->where('public', true);
        };
        config(['binding-test.tenant' => 'b']);
        $this->getJson('/bound')->assertOk()->assertJsonPath('props.bindings.repeat-one.records', [['title' => 'Visible B', 'url' => null]]);
        $resource->publicRead = null;
        $this->getJson('/bound')->assertOk()->assertJsonPath('props.bindings.repeat-one.records', []);
    }

    public function test_public_floor_does_not_change_an_allowed_reader(): void
    {
        $resource = app(ParticleResourceRegistry::class)->get('binding-records');
        $resource->readPolicy = \Splicewire\Beam\Authorization\AuthenticatedReadPolicy::class;
        $resource->readBoundary = ParticleResource::READ_BOUNDARY_GLOBAL;
        $this->actingAs((new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 7]));
        $rows = $this->getJson('/bound')->assertOk()->json('props.bindings.repeat-one.records');
        $this->assertSame(['Visible A', 'Author only'], array_column($rows, 'title'));
    }

    public function test_denied_reader_cannot_use_hidden_query_fields_on_the_public_floor(): void
    {
        $this->installCollectionFixtures();
        app(ParticleResourceRegistry::class)->get('records')->readPolicy = BindingDeniedPolicy::class;
        $this->actingAs((new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 7]));
        foreach ([['filter' => ['secret' => 'private field']], ['sort' => 'secret']] as $query) {
            app(PublishedBindingDocument::class)->savedQuery = $query;
            $this->getJson('/bound')->assertUnprocessable();
        }
        app(PublishedBindingDocument::class)->savedQuery = [];
        $this->getJson('/bound')->assertOk()->assertJsonPath('props.bindings.articles.records', [['fields' => ['title' => 'articles public'], 'url' => null]]);
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

    public function test_profile_collection_fields_are_generated_from_the_owner(): void
    {
        $entry = BeamUxEntry::where('slug', 'bound')->firstOrFail();
        $plain = app(\Splicewire\Beam\Ux\Bindings\ProfileCollections::class)->manifest($entry, 'content');
        $this->assertArrayHasKey('title', $plain[0]['schema']['properties']);
        $this->assertArrayHasKey('secret', $plain[0]['schema']['properties']);
        $this->installCollectionFixtures();
        $collections = app(\Splicewire\Beam\Ux\Bindings\ProfileCollections::class)->manifest($entry, 'content');
        $this->assertSame('https://example.test/articles/1', $collections[0]['schema']['$id']);
        $this->assertSame('https://example.test/events/1', $collections[1]['schema']['$id']);
    }

    public function test_nested_relation_bindings_batch_target_public_reads_for_all_outer_rows(): void
    {
        $this->installCollectionFixtures();
        $registry = new \Splicewire\Beam\Schema\DatabaseSchemaRegistry;
        $schema = app(\Splicewire\Beam\Collections\CollectionSchemaResolver::class)->resolve('https://example.test/articles')->schema;
        $schema['$id'] = 'https://example.test/articles/2';
        $schema['properties']['related'] = ['type' => 'array', 'items' => ['type' => 'string'], 'x-beam-relation' => ['collection' => 'https://example.test/events']];
        $schema['x-beam-public-read']['fields'][] = 'related';
        $registry->register($schema);
        $table = \Splicewire\Beam\Facades\Beam::table('particles');
        foreach (range(1, 12) as $i) {
            \Illuminate\Support\Facades\DB::table($table)->insert([
                'id' => 'source-'.$i, 'schema_ref' => 'https://example.test/articles/1', 'schema_id' => 'https://example.test/articles/1',
                'payload' => json_encode(['title' => 'source '.$i, 'visible' => true, 'related' => ['events-1', 'events-0', 'missing']]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['private-only' => 'events-0', 'missing-only' => 'missing'] as $id => $target) {
            \Illuminate\Support\Facades\DB::table($table)->insert([
                'id' => $id, 'schema_ref' => 'https://example.test/articles/1', 'schema_id' => 'https://example.test/articles/1',
                'payload' => json_encode(['title' => $id, 'visible' => true, 'related' => [$target]]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument
        {
            public function read(BeamUxEntry $entry): array
            {
                return [['kind' => 'block', 'id' => 'outer', 'name' => 'article', 'repeat' => ['collection' => 'articles'],
                    'children' => [['kind' => 'block', 'id' => 'inner', 'name' => 'p', 'repeat' => ['relation' => 'related'],
                        'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title'], ['kind' => 'text', 'value' => '', 'field' => 'fields.secret']]]]]];
            }
        });
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $response = $this->getJson('/bound')->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $targets = array_filter($queries, fn ($q) => str_contains($q['query'], $table) && in_array('https://example.test/events/1', $q['bindings'], true));
        $this->assertCount(1, $targets);
        $rows = $response->json('props.bindings.outer.records');
        $this->assertCount(15, $rows);
        $populated = 0;
        foreach ($rows as $row) {
            $nested = $row['bindings']['inner'];
            if ($nested['records'] !== []) {
                $populated++;
                $this->assertSame([['fields' => ['title' => 'events public'], 'url' => null]], $nested['records']);
            }
        }
        $this->assertSame(12, $populated);
        $this->assertStringNotContainsString('author only', $response->getContent());
        $this->assertStringNotContainsString('private field', $response->getContent());
        app(ParticleResourceRegistry::class)->get('records')->readPolicy = BindingDeniedPolicy::class;
        $this->actingAs((new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 7]));
        $this->getJson('/bound')->assertOk()->assertJsonPath('props.bindings.outer.records', $rows);
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

    public function test_child_page_bindings_use_the_visitor_gates_and_canonical_urls(): void
    {
        $parent = BeamUxEntry::where('slug', 'bound')->firstOrFail();
        foreach (['visible', 'private', 'draft'] as $slug) {
            BeamUxEntry::create(['slug' => $slug, 'segment' => $slug, 'title' => ucfirst($slug),
                'type' => UxType::Page, 'parent_id' => $parent->id,
                'access' => $slug === 'private' ? ['auth'] : null]);
        }
        $this->app->instance(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class, new class implements \Splicewire\Beam\Ux\Sitemap\EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->slug !== 'draft';
            }
        });
        $this->app->instance(ManifestCollectionSource::class, new class($parent->id) implements ManifestCollectionSource
        {
            public function __construct(private string $parent) {}

            public function collections(BeamUxEntry $entry, string $profile): array
            {
                return [CollectionSource::childPages('children', $this->parent)];
            }
        });
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument
        {
            public function read(BeamUxEntry $entry): array
            {
                return [['kind' => 'block', 'id' => 'children', 'name' => 'a',
                    'repeat' => ['collection' => 'children'],
                    'props' => ['href' => ['kind' => 'field', 'value' => 'url']],
                    'children' => [['kind' => 'text', 'value' => '', 'field' => 'title']]]];
            }
        });
        $response = $this->getJson('/bound')->assertOk();
        $this->assertSame([['url' => '/bound/visible', 'title' => 'Visible']], $response->json('props.bindings.children.records'));
        $this->actingAs((new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 7]));
        $response = $this->getJson('/bound')->assertOk();
        $this->assertSame(['Private', 'Visible'], array_column($response->json('props.bindings.children.records'), 'title'));
        $parent->update(['traverse' => ['missing-grant']]);
        $this->getJson('/bound')->assertOk()->assertJsonPath('props.bindings.children.records', []);
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
        $this->app->instance(ManifestCollectionSource::class, new class implements ManifestCollectionSource
        {
            public function collections(BeamUxEntry $entry, string $profile): array
            {
                return array_map(fn ($name) => new CollectionSource($name, 'records', ['collection' => 'https://example.test/'.$name]), ['articles', 'events']);
            }
        });
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument
        {
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

class BindingDeniedPolicy implements \Splicewire\Beam\Authorization\ResourceReadPolicy
{
    public function inspect(?\Illuminate\Contracts\Auth\Authenticatable $actor, ParticleResource $resource, \Illuminate\Http\Request $request): \Illuminate\Auth\Access\Response
    {
        return \Illuminate\Auth\Access\Response::deny();
    }
}
