<?php

namespace Splicewire\Beam\Ux\Tests\Sync;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\Reasserter;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Tests\TestCase;
use Splicewire\Sync\Contracts\SyncLineageResolver;

/** Real version/seed storage on :memory:. Compilation is stubbed; this is not a browser/artifact proof. */
final class PackSettingsTest extends TestCase
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
        self::assertSame(':memory:', Schema::getConnection()->getDatabaseName());
        $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
        foreach (['create_beam_particles_table', 'create_beam_versions_table', 'create_activity_log_table'] as $name) {
            (require $core.'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        foreach (['create_beam_ux_entries_table', 'add_page_settings_to_beam_ux_entries_table', 'add_child_fields_to_beam_ux_entries_table', 'add_record_page_to_beam_ux_entries_table', 'add_component_kind_to_beam_ux_entries_table', 'add_component_names_to_beam_ux_entries_table', 'add_provenance_to_beam_ux_entries_table'] as $name) {
            (require dirname(__DIR__, 2).'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        $compiler = $this->createStub(CompileEntryBody::class);
        $compiler->method('forEntry')->willReturn(null);
        $this->app->instance(CompileEntryBody::class, $compiler);
        $this->app->forgetInstance(Reasserter::class);
        Gate::define('create', static fn ($user = null) => true);
        Gate::define('update', static fn ($user = null) => true);
    }

    private function body(string $text): array
    {
        return [['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => $text]]]];
    }

    private function seedSettings(string $slug, EntryPageSettingsData $settings, string $text = 'Published'): BeamUxEntry
    {
        return app(Reasserter::class)->seedBody(['namespace' => null, 'slug' => $slug, 'realm' => 'site', 'type' => 'page'],
            ['title' => $settings->title, 'format' => 'tsx', 'parent_id' => $settings->parentId], $this->body($text), Provenance::package('splicewire/pack-settings-fixture'), $settings);
    }

    public function test_export_settings_attributes_and_parent_follow_the_publication_pin_not_a_draft(): void
    {
        $source = $this->seedSettings('source', new EntryPageSettingsData('Published title', fields: ['servings' => 2]));
        $particle = $source->versionable();
        $meta = $particle->meta;
        $meta['pageSettings']['title'] = 'Unreleased draft';
        $meta['pageSettings']['fields'] = ['servings' => 99];
        $particle->forceFill(['payload' => $this->body('Unreleased draft'), 'meta' => $meta])->save();
        app(VersionStore::class)->snapshot($particle, 'unreleased');
        $source->forceFill(['title' => 'Unreleased draft'])->save();

        $payload = $source->fresh()->toSyncPayload();
        self::assertSame('Published title', $payload['attributes']['title']);
        self::assertSame(['servings' => 2], $payload['pageSettings']['fields']);
        self::assertSame($this->body('Published'), $payload['body']);
        self::assertNull($payload['parent_id']);
        self::assertStringNotContainsString('Unreleased draft', json_encode($payload));
    }

    public function test_first_copy_keeps_settings_in_the_row_and_published_version_then_retains_tenant_fields(): void
    {
        $parent = $this->seedSettings('parent', new EntryPageSettingsData('Parent', childFields: 'recipe'));
        $source = $this->seedSettings('source', new EntryPageSettingsData('Child', parentId: (string) $parent->id, fields: ['servings' => 2]));
        $parentPayload = $parent->toSyncPayload();
        $parentPayload['coordinate']['slug'] = 'copied-parent';
        $config = [BeamUxEntry::PACK_SLUG_CONFIG => 'settings-starter'];
        $copyParent = BeamUxEntry::fromSyncPayload($parentPayload, $config);
        $lineage = $this->createMock(SyncLineageResolver::class);
        $lineage->method('resolveTargetId')->with(BeamUxEntry::class, (string) $parent->id)->willReturn((string) $copyParent->id);
        $config[SyncLineageResolver::CONFIG_KEY] = $lineage;
        $payload = $source->toSyncPayload();
        $payload['coordinate']['slug'] = 'copied-child';
        $copy = BeamUxEntry::fromSyncPayload($payload, $config);
        self::assertSame('recipe', $copyParent->child_fields);
        self::assertSame((string) $copyParent->id, (string) $copy->parent_id);
        self::assertSame(['servings' => 2], app(EntryPublication::class)->publishedSettingsOf($copy)->fields);

        $particle = $copy->versionable();
        $meta = $particle->meta;
        $meta['pageSettings']['fields'] = ['servings' => 9];
        $particle->meta = $meta;
        $particle->save();
        $sourceParticle = $source->versionable();
        $sourceMeta = $sourceParticle->meta;
        $sourceMeta['pageSettings']['fields'] = ['servings' => 4];
        $sourceParticle->meta = $sourceMeta;
        $sourceParticle->save();
        $this->seedSettings('source', new EntryPageSettingsData('Child', parentId: (string) $parent->id, fields: ['servings' => 4]), 'Updated source');
        $payload = $source->fresh()->toSyncPayload();
        $payload['coordinate']['slug'] = 'copied-child';
        $reapplied = BeamUxEntry::fromSyncPayload($payload, $config);
        self::assertSame($this->body('Updated source'), app(EntryPublication::class)->publishedBodyOf($reapplied));
        self::assertSame(['servings' => 9], app(EntryPublication::class)->publishedSettingsOf($reapplied)->fields);
        self::assertSame((string) $copyParent->id, (string) $reapplied->parent_id);
    }
}
