<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Bindings\CollectionSource;
use Splicewire\Beam\Ux\Bindings\ManifestCollectionSource;
use Splicewire\Beam\Ux\Bindings\PublishedBindingDocument;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Data\RecordPageData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

class RecordPageTest extends TestCase
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
                'description' => ['type' => 'string'], 'visible' => ['type' => 'boolean'], 'secret' => ['type' => 'string']],
            'x-beam-public-read' => ['fields' => ['slug', 'title', 'description'], 'where' => ['all' => [['field' => 'visible', 'operator' => 'equals', 'value' => true]]]],
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
                'payload' => json_encode(['slug' => $slug, 'title' => 'Record '.$slug, 'description' => 'SEO '.$slug, 'visible' => $visible, 'secret' => 'NEVER PUBLIC'])]);
        }
    }

    private function page(): BeamUxEntry
    {
        $parent = BeamUxEntry::create(['slug' => 'posts', 'type' => UxType::Page, 'segment' => 'posts', 'parent_id' => BeamUxEntry::rootFor()->id]);

        return BeamUxEntry::create(['slug' => 'post', 'type' => UxType::Page, 'segment' => '{slug}', 'parent_id' => $parent->id,
            'record_page' => ['collection' => 'posts', 'field' => 'slug', 'titleField' => 'title', 'descriptionField' => 'description']]);
    }

    public function test_parameter_page_uses_the_visitors_record_and_seo_without_private_fields(): void
    {
        $page = $this->page();
        $response = $this->getJson('/posts/hello')->assertOk();
        $response->assertJsonPath('props.entry.id', $page->id)->assertJsonPath('props.entry.url', '/posts/hello')
            ->assertJsonPath('props.entry.title', 'Record hello')->assertJsonPath('props.entry.description', 'SEO hello')
            ->assertJsonPath('props.record.fields.slug', 'hello');
        $this->assertStringNotContainsString('NEVER PUBLIC', $response->getContent());
        $this->assertStringNotContainsString('{slug}', json_encode($response->json('props.nav')));
    }

    public function test_missing_and_unreadable_records_have_the_same_public_answer_and_literal_wins(): void
    {
        $page = $this->page();
        $hidden = $this->getJson('/posts/hidden')->assertNotFound();
        $absent = $this->getJson('/posts/absent')->assertNotFound();
        $this->assertSame($hidden->getContent(), $absent->getContent());
        $literal = BeamUxEntry::create(['slug' => 'literal', 'type' => UxType::Page, 'segment' => 'hello', 'parent_id' => $page->parent_id]);
        $this->getJson('/posts/hello')->assertOk()->assertJsonPath('props.entry.id', $literal->id);
    }

    public function test_save_refuses_a_non_unique_field_and_a_second_record_page(): void
    {
        $page = $this->page();
        $settings = new EntryPageSettingsData(title: 'Template', parentId: $page->parent_id, segment: '{title}', recordPage: new RecordPageData('posts', 'title'));
        try {
            app(RecordPages::class)->assertSettings($page, $settings);
            $this->fail('non-unique admitted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('pageSettings.recordPage', $e->errors());
        }
        $second = BeamUxEntry::create(['slug' => 'second', 'type' => UxType::Page, 'segment' => 'unused', 'parent_id' => $page->parent_id]);
        $settings = new EntryPageSettingsData(title: 'Second', parentId: $second->parent_id, segment: '{slug}', recordPage: new RecordPageData('posts', 'slug'));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RecordPages::class)->assertSettings($second, $settings);
    }

    public function test_guest_sitemap_expands_records_and_never_lists_the_parameter_or_private_record(): void
    {
        $this->page();
        $urls = array_map(fn ($url) => $url->url, iterator_to_array(app(\Splicewire\Beam\Ux\Sitemap\EntrySitemapSource::class)->urls()));
        $this->assertTrue((bool) array_filter($urls, fn ($url) => str_ends_with($url, '/posts/hello')));
        $this->assertFalse((bool) array_filter($urls, fn ($url) => str_contains($url, '{') || str_ends_with($url, '/hidden')));
    }

    public function test_record_page_body_fields_have_the_collection_scope_and_printer_accepts_the_record_prop(): void
    {
        $entry = $this->page();
        $body = [['kind' => 'block', 'name' => 'h1', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'fallback', 'field' => 'fields.title']]]];
        app(\Splicewire\Beam\Ux\Bindings\BindingBodyGuard::class)->assert($entry, $body, [
            'x-collections' => app(\Splicewire\Beam\Ux\Bindings\ProfileCollections::class)->manifest($entry, 'content'),
        ]);
        $printed = \Splicewire\Beam\Ux\Codec\JsonDocPrinter::printModule($body);
        $this->assertStringContainsString('bindingRuntime.text(record,', $printed);
        $this->assertStringContainsString('record,', $printed);
    }

    public function test_offered_collection_reports_its_canonical_record_page_url(): void
    {
        $entry = $this->page();
        $profiles = app(\Splicewire\Beam\Ux\Bindings\ProfileCollections::class);
        $this->assertTrue($profiles->manifest($entry, 'content')[0]['hasRecordPage']);
        $source = $profiles->require($entry, 'posts');
        $page = app(RecordPages::class)->pageFor($entry, $source);
        $this->assertSame('/posts/hello', app(RecordPages::class)->url($page, ['fields' => ['slug' => 'hello']]));
    }

    public function test_draft_input_and_file_roundtrip_preserve_the_record_declaration(): void
    {
        $page = $this->page();
        $declaration = ['collection' => 'posts', 'field' => 'slug', 'titleField' => 'title', 'descriptionField' => null, 'socialImageField' => null];
        $input = \Splicewire\Beam\Ux\Data\EntryPageSettingsInputData::validateAndCreate(['title' => 'Template', 'segment' => '{slug}', 'recordPage' => $declaration]);
        $settings = $input->toPageSettingsData();
        $this->assertSame($declaration, $settings->recordPage->toArray());
        $bytes = \Splicewire\Beam\Ux\Files\MdxPageFile::print($settings, 'Body');
        $read = \Splicewire\Beam\Ux\Files\MdxPageFile::read($bytes);
        $restored = \Splicewire\Beam\Ux\Files\MdxPageFile::settingsFrom($read['fields'], $read['unknown'], $page);
        $this->assertSame($declaration, $restored->recordPage->toArray());
    }

    public function test_validation_does_not_cache_a_null_particle_on_the_entry_before_its_first_write(): void
    {
        $entry = $this->page()->fresh();
        $this->assertFalse($entry->relationLoaded('particle'));
        app(RecordPages::class)->validationEntry($entry);
        $this->assertFalse($entry->relationLoaded('particle'));
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
