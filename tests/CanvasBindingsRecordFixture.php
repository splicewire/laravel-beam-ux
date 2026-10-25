<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Bindings\CollectionSource;
use Splicewire\Beam\Ux\Bindings\ManifestCollectionSource;
use Splicewire\Beam\Ux\Bindings\PublishedBindingDocument;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/** Fixture-only copy of the established RecordPageTest native read composition; no inherited tests. */
abstract class CanvasBindingsRecordFixture extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', \Illuminate\Support\Facades\DB::connection()->getDatabaseName());
        $this->createTables();
        \Illuminate\Support\Facades\Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return new \Illuminate\Http\JsonResponse(['props' => $props]);
            }
        });
        $this->app->instance(PublishedBindingDocument::class, new class extends PublishedBindingDocument
        {
            public function read(BeamUxEntry $entry): array
            {
                return [];
            }
        });
        \Illuminate\Support\Facades\Route::beamUxSite('site/entry');
        Schema::create('beam_schemas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name');
            $table->integer('version');
            $table->string('fingerprint');
            $table->json('artifact');
            $table->timestamps();
        });
        Schema::create('beam_particles', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('schema_ref');
            $table->string('schema_id');
            $table->string('source_tier')->default('local');
            $table->string('migration_status')->default('current');
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->string('head_version')->nullable();
            $table->timestamps();
        });
        $registry = new \Splicewire\Beam\Schema\DatabaseSchemaRegistry;
        $registry->register([
            '$id' => 'https://example.test/posts/1', 'type' => 'object',
            'x-beam-collection' => ['label' => 'Posts', 'nameField' => 'title'],
            'properties' => ['slug' => ['type' => 'string', 'x-beam-unique' => true], 'title' => ['type' => 'string'],
                'image' => ['type' => 'string'], 'description' => ['type' => 'string'], 'visible' => ['type' => 'boolean'], 'secret' => ['type' => 'string']],
            'x-beam-public-read' => ['fields' => ['slug', 'title', 'description', 'image'], 'where' => ['all' => [['field' => 'visible', 'operator' => 'equals', 'value' => true]]]],
        ]);
        $this->app->instance(\Splicewire\Beam\Collections\CollectionSchemaResolver::class,
            new \Splicewire\Beam\Collections\CollectionSchemaResolver(new \Splicewire\Beam\Schema\RegistrySchemaTargetResolver($registry), $registry));
        $reconciler = \Mockery::mock(\Rushing\Versioning\Contracts\RecordReconciler::class);
        $reconciler->shouldReceive('currentId')->andReturn('https://example.test/posts/1');
        $reconciler->shouldReceive('reconcile')->andReturnUsing(fn ($payload, $id) => \Rushing\Versioning\MigrationOutcome::current($id));
        $this->app->instance(\Rushing\Versioning\Contracts\RecordReconciler::class, $reconciler);
        $this->app->instance(ManifestCollectionSource::class, new class implements ManifestCollectionSource
        {
            public function collections(BeamUxEntry $entry, string $profile): array
            {
                return [new CollectionSource('posts', 'records', ['collection' => 'https://example.test/posts'])];
            }
        });
        foreach (['hello' => true, 'hidden' => false] as $slug => $visible) {
            \Illuminate\Support\Facades\DB::table('beam_particles')->insert(['id' => $slug, 'schema_ref' => 'https://example.test/posts/1', 'schema_id' => 'https://example.test/posts/1',
                'payload' => json_encode(['slug' => $slug, 'title' => 'Record '.$slug, 'image' => '/images/'.$slug.'.png', 'description' => 'SEO '.$slug, 'visible' => $visible, 'secret' => 'NEVER PUBLIC'])]);
        }
    }

    protected function page(): BeamUxEntry
    {
        $parent = BeamUxEntry::create(['slug' => 'posts', 'type' => UxType::Page, 'segment' => 'posts', 'parent_id' => BeamUxEntry::rootFor()->id]);

        return BeamUxEntry::create(['slug' => 'post', 'type' => UxType::Page, 'segment' => '{slug}', 'parent_id' => $parent->id,
            'record_page' => ['collection' => 'posts', 'field' => 'slug', 'titleField' => 'title', 'descriptionField' => 'description', 'socialImageField' => 'image']]);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('published_version')->nullable();
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
            $table->json('record_page')->nullable();
            $table->string('seo_description')->nullable();
            $table->string('seo_social_image')->nullable();
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
