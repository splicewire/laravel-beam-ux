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

    private function page(): BeamUxEntry
    {
        $parent = BeamUxEntry::create(['slug' => 'posts', 'type' => UxType::Page, 'segment' => 'posts', 'parent_id' => BeamUxEntry::rootFor()->id]);

        return BeamUxEntry::create(['slug' => 'post', 'type' => UxType::Page, 'segment' => '{slug}', 'parent_id' => $parent->id,
            'record_page' => ['collection' => 'posts', 'field' => 'slug', 'titleField' => 'title', 'descriptionField' => 'description', 'socialImageField' => 'image']]);
    }

    public function test_parameter_page_uses_the_visitors_record_and_seo_without_private_fields(): void
    {
        $page = $this->page();
        $response = $this->getJson('/posts/hello')->assertOk();
        $response->assertJsonPath('props.entry.id', $page->id)->assertJsonPath('props.entry.url', '/posts/hello')
            ->assertJsonPath('props.entry.title', 'Record hello')->assertJsonPath('props.entry.description', 'SEO hello')
            ->assertJsonPath('props.record.fields.slug', 'hello')->assertJsonPath('props.entry.socialImage', '/images/hello.png');
        $this->assertStringNotContainsString('NEVER PUBLIC', $response->getContent());
        $this->assertStringNotContainsString('{slug}', json_encode($response->json('props.nav')));
    }

    public function test_a_record_pages_head_reads_its_record_at_the_records_own_url(): void
    {
        // ADR-0234 d1/d4 (EP-28): a link preview of /posts/hello names that Record, not the parameter page.
        config(['app.url' => 'https://site.test', 'beam.brand.name' => 'Acme']);
        $this->page();

        $head = $this->getJson('/posts/hello')->assertOk()->json('props.head');

        $this->assertSame('<title data-inertia="title">Record hello - Acme</title>', $head[0]);
        $this->assertContains('<meta data-inertia="description" name="description" content="SEO hello">', $head);
        $this->assertContains('<link data-inertia="canonical" rel="canonical" href="https://site.test/posts/hello">', $head);
        $this->assertContains('<meta data-inertia="og:image" property="og:image" content="https://site.test/images/hello.png">', $head);
    }

    public function test_a_record_pages_graph_carries_its_record_only_where_a_projector_is_registered(): void
    {
        // ADR-0234 d9 and the EP-28 ruling: the Record's node comes from the projector registered for its Data class, as
        // the page's main entity. A Record no projector maps adds nothing, which is every starter today (d12).
        config(['app.url' => 'https://site.test', 'beam.brand.name' => 'Acme']);
        $this->page();
        $graph = function (): array {
            $head = $this->getJson('/posts/hello')->assertOk()->json('props.head');
            $script = array_values(preg_grep('#^<script data-inertia="structured-data"#', $head))[0];

            return array_column(json_decode((string) preg_replace('#^<script[^>]*>|</script>$#', '', $script), true)['@graph'], null, '@type');
        };

        $this->assertArrayNotHasKey('mainEntity', $graph()['WebPage']);
        $this->assertSame(['WebPage', 'BreadcrumbList'], array_keys($graph()));

        app(\Schemastud\DataSchemaOrg\SchemaOrgRegistry::class)->register(new class implements \Schemastud\DataSchemaOrg\Contracts\SchemaOrgProjector
        {
            public function subject(): string
            {
                return \Splicewire\Beam\Collections\PublicRecordData::class;
            }

            public function project(\Spatie\LaravelData\Data $data, \Schemastud\DataSchemaOrg\ProjectionContext $context): \Spatie\SchemaOrg\Type
            {
                return (new \Spatie\SchemaOrg\BlogPosting)->headline($data->fields['title'])->setProperty('@id', $context->id);
            }
        });
        $nodes = $graph();

        $this->assertSame(['@id' => 'https://site.test/posts/hello#record'], $nodes['WebPage']['mainEntity']);
        $this->assertSame(['@type' => 'BlogPosting', 'headline' => 'Record hello', '@id' => 'https://site.test/posts/hello#record'], $nodes['BlogPosting']);
        $this->assertSame('https://site.test/posts/hello', end($nodes['BreadcrumbList']['itemListElement'])['item'], 'the trail ends at the Record');
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

    public function test_slug_lookup_filters_before_public_projection_and_has_a_bounded_query_count(): void
    {
        $this->page();
        for ($i = 0; $i < 220; $i++) {
            \Illuminate\Support\Facades\DB::table('beam_particles')->insert(['id' => 'bulk-'.$i, 'schema_ref' => 'https://example.test/posts/1', 'schema_id' => 'https://example.test/posts/1',
                'payload' => json_encode(['slug' => 'bulk-'.$i, 'title' => 'Bulk', 'visible' => true])]);
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $missing = $this->getJson('/posts/absent')->assertNotFound();
        $queries = array_values(array_filter(\Illuminate\Support\Facades\DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "beam_particles"')));
        $this->assertLessThanOrEqual(6, count($queries));
        foreach ($queries as $query) {
            $this->assertContains('absent', $query['bindings'], 'Every particle read, including Public Read evaluation, must already be narrowed to this slug.');
        }
        $hidden = $this->getJson('/posts/hidden')->assertNotFound();
        $this->assertSame($missing->getContent(), $hidden->getContent());
        $headers = fn ($response) => array_diff_key($response->headers->all(), ['date' => true]);
        $this->assertSame($headers($missing), $headers($hidden));
    }

    public function test_a_draft_moving_away_does_not_release_its_still_published_record_page(): void
    {
        $page = $this->page();
        \Illuminate\Support\Facades\DB::table('beam_particles')->insert(['id' => 'page-draft', 'schema_ref' => 'https://example.test/posts/1', 'schema_id' => 'https://example.test/posts/1', 'payload' => '{}',
            'meta' => json_encode(['pageSettings' => ['title' => 'Moving away', 'segment' => 'plain', 'recordPage' => null]])]);
        $page->update(['particle_id' => 'page-draft']);
        $other = BeamUxEntry::create(['slug' => 'other', 'type' => UxType::Page, 'parent_id' => $page->parent_id, 'segment' => 'other']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RecordPages::class)->assertSettings($other, new EntryPageSettingsData(title: 'Other', parentId: $other->parent_id, segment: '{slug}', recordPage: new RecordPageData('posts', 'slug')));
    }

    public function test_sitemap_does_not_resolve_the_whole_entry_tree_for_every_record(): void
    {
        $this->page();
        for ($i = 0; $i < 40; $i++) {
            \Illuminate\Support\Facades\DB::table('beam_particles')->insert(['id' => 'sitemap-'.$i, 'schema_ref' => 'https://example.test/posts/1', 'schema_id' => 'https://example.test/posts/1',
                'payload' => json_encode(['slug' => 'item-'.$i, 'title' => 'Item', 'visible' => true])]);
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $urls = iterator_to_array(app(\Splicewire\Beam\Ux\Sitemap\EntrySitemapSource::class)->urls());
        $this->assertGreaterThanOrEqual(41, count($urls));
        $this->assertLessThanOrEqual(35, count(\Illuminate\Support\Facades\DB::getQueryLog()));
    }

    public function test_save_refuses_a_unique_field_that_is_not_publicly_projected(): void
    {
        $page = $this->page();
        (new \Splicewire\Beam\Schema\DatabaseSchemaRegistry)->register([
            '$id' => 'https://example.test/posts/2', 'type' => 'object',
            'x-beam-collection' => ['label' => 'Posts', 'nameField' => 'title'],
            'properties' => ['slug' => ['type' => 'string', 'x-beam-unique' => true], 'title' => ['type' => 'string']],
            'x-beam-public-read' => ['fields' => ['title']],
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('Public Read projection');
        app(RecordPages::class)->assertSettings($page, new EntryPageSettingsData(title: 'Page', segment: '{slug}', recordPage: new RecordPageData('posts', 'slug')));
    }

    public function test_preview_resolves_the_frozen_parameter_settings_without_changing_the_published_page(): void
    {
        $page = $this->page();
        $declaration = $page->record_page;
        \Illuminate\Support\Facades\DB::table('beam_particles')->insert(['id' => 'preview-body', 'schema_ref' => 'https://example.test/posts/1', 'schema_id' => 'https://example.test/posts/1', 'payload' => '[]']);
        $page->update(['particle_id' => 'preview-body', 'segment' => 'published-literal', 'record_page' => null]);
        $version = new \Rushing\Versioning\Models\Version;
        $version->forceFill(['snapshot' => ['payload' => [], 'meta' => ['pageSettings' => [
            'title' => 'Frozen template', 'parentId' => $page->parent_id, 'segment' => '{slug}', 'recordPage' => $declaration,
        ]]]]);
        $store = \Mockery::mock(\Rushing\Versioning\Contracts\VersionStore::class);
        $store->shouldReceive('version')->with(\Mockery::type(\Splicewire\Beam\Models\BeamParticle::class), 'saved-version')->andReturn($version);
        $this->app->instance(\Rushing\Versioning\Contracts\VersionStore::class, $store);
        $grant = new \Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;
        $grant->forceFill(['entry_id' => $page->id, 'version_id' => 'saved-version']);
        $context = new \Splicewire\Beam\Ux\Preview\PreviewContext($grant);
        $chain = app(\Splicewire\Beam\Ux\Containment\PreviewEntryPathResolver::class)->resolve('/posts/hello', 'site', $context);
        $this->assertNotNull($chain);
        $projected = $chain[array_key_last($chain)];
        $this->assertSame($page->id, $projected->id);
        $this->assertSame($declaration, $projected->record_page);
        $this->assertSame('hello', $projected->getAttribute('_record_page_value'));
        $this->assertSame('hello', app(RecordPages::class)->resolve($projected, 'hello', \Illuminate\Http\Request::create('/posts/hello'), 'site')['fields']['slug']);
        $this->assertSame('published-literal', $page->fresh()->segment);
        $this->assertNull($page->fresh()->record_page);
    }

    public function test_sitemap_snapshot_keeps_literal_parameter_and_legacy_realm_routing_parity(): void
    {
        $page = $this->page();
        BeamUxEntry::create(['slug' => 'literal', 'type' => UxType::Page, 'segment' => 'hello', 'parent_id' => $page->parent_id]);
        foreach ([false, true] as $legacy) {
            if ($legacy) {
                Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropColumn('realms'));
                app(\Splicewire\Beam\Ux\Bindings\RecordPageScope::class)->realmsColumns = [];
            }
            $resolver = app(\Splicewire\Beam\Ux\Containment\EntryPathResolver::class);
            $snapshot = $resolver->snapshot(BeamUxEntry::all());
            foreach (['/posts', '/posts/hello', '/posts/other', '/missing'] as $path) {
                $ids = fn ($chain) => $chain === null ? null : array_map(fn ($entry) => $entry->id, $chain);
                $this->assertSame($ids($resolver->resolve($path)), $ids($snapshot->resolve($path)), $path);
            }
        }
    }

    public function test_a_new_container_scope_reloads_published_record_page_metadata(): void
    {
        $page = $this->page();
        $service = app(RecordPages::class);
        $this->assertTrue($service->hasPublished($page));
        \Illuminate\Support\Facades\DB::table('beam_ux_entries')->where('id', $page->id)->update(['record_page' => null]);
        $this->assertTrue($service->hasPublished($page), 'Metadata is reusable only within this scope.');
        $this->app->forgetScopedInstances();
        $this->assertFalse($service->hasPublished($page));
    }

    public function test_draft_locks_the_ordered_primary_key_set_before_the_own_row(): void
    {
        Schema::create('beam_versions', fn (Blueprint $table) => $table->uuid('id')->primary());
        $page = $this->page();
        $entry = BeamUxEntry::create(['slug' => 'other', 'type' => UxType::Page, 'parent_id' => $page->parent_id, 'segment' => 'other']);
        $observed = null;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$observed) {
            if ($observed === null && str_starts_with($query->sql, 'select * from "beam_ux_entries"')) {
                $observed = $query;
                throw new \RuntimeException('stop-before-draft-state');
            }
        });
        try {
            app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->draftFromBase($entry, [], null, pageSettings: new EntryPageSettingsData(title: 'Other', parentId: $entry->parent_id, segment: '{slug}', recordPage: new RecordPageData('posts', 'slug')));
            $this->fail('The query probe was not reached.');
        } catch (\RuntimeException $error) {
            $this->assertSame('stop-before-draft-state', $error->getMessage());
        }
        $this->assertNotNull($observed);
        $this->assertStringContainsString(' in (', $observed->sql);
        $this->assertStringContainsString('order by "id" asc', $observed->sql);
        $ids = [$page->id, $entry->id, $entry->parent_id];
        sort($ids);
        $this->assertSame($ids, $observed->bindings);
    }

    public function test_a_changed_candidate_set_refuses_instead_of_extending_held_locks(): void
    {
        $page = $this->page();
        $entry = BeamUxEntry::create(['slug' => 'other', 'type' => UxType::Page, 'parent_id' => $page->parent_id, 'segment' => 'other']);
        $changed = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$changed) {
            if (! $changed && str_starts_with($query->sql, 'select * from "beam_ux_entries"') && str_contains($query->sql, ' in (')) {
                $changed = true;
                BeamUxEntry::create(['slug' => 'new-candidate', 'type' => UxType::Page, 'segment' => 'new-candidate', 'record_page' => ['collection' => 'posts', 'field' => 'slug']]);
            }
        });
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->expectExceptionMessage('declarations changed');
        $entry->getConnection()->transaction(fn () => app(RecordPages::class)->lockForSettings($entry, new EntryPageSettingsData(title: 'Other', parentId: $entry->parent_id, segment: '{slug}', recordPage: new RecordPageData('posts', 'slug'))));
    }

    public function test_sitemap_selects_routing_columns_and_page_rows_only(): void
    {
        $this->page();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        iterator_to_array(app(\Splicewire\Beam\Ux\Sitemap\EntrySitemapSource::class)->urls());
        $queries = array_filter(\Illuminate\Support\Facades\DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "beam_ux_entries"'));
        $first = array_values($queries)[0];
        $this->assertStringNotContainsString('select *', $first['query']);
        $this->assertContains('page', $first['bindings']);
        $this->assertStringNotContainsString('"particle_id"', $first['query']);
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
