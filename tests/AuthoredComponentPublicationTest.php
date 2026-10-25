<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Components\ComponentDelivery;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/** Native version store, owning migration stubs and real Node compiler, on a fresh in-memory database. */
class AuthoredComponentPublicationTest extends TestCase
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
        $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
        foreach (['create_beam_particles_table', 'create_beam_versions_table', 'create_activity_log_table'] as $name) {
            (require $core.'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        foreach (['create_beam_ux_entries_table', 'add_page_settings_to_beam_ux_entries_table', 'add_child_fields_to_beam_ux_entries_table', 'add_record_page_to_beam_ux_entries_table', 'add_component_kind_to_beam_ux_entries_table'] as $name) {
            (require dirname(__DIR__).'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        $this->assertSame('sqlite', Schema::getConnection()->getDriverName());
        $this->assertSame(':memory:', Schema::getConnection()->getDatabaseName());
        Storage::fake('component-artifacts');
        Storage::fake('component-mirror');
        config(['beam.ux.compile.disk' => 'component-artifacts', 'beam.ux.storage.mirror_disk' => 'component-mirror']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new NodeEntryBodyCompiler(workingDirectory: __DIR__.'/Fixtures/entry-body-host'));
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    private function block(string $name, array $children = []): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'dynamic' => false, 'props' => [], 'children' => $children];
    }

    private function card(string $text): array
    {
        return [$this->block('div', [['kind' => 'text', 'value' => $text]]) + ['component' => ['name' => 'NativeCard', 'props' => []]]];
    }

    private function publicComponents(BeamUxEntry $page): array
    {
        Route::get('/component-artifacts/{entry}/{version}', fn () => '')->name('ep20.artifact');
        $route = Route::get('/component-test-page', fn () => '')->defaults('beamUxArtifactRoute', 'ep20.artifact');
        Route::getRoutes()->refreshNameLookups();
        $this->assertTrue(app('router')->has('ep20.artifact'));
        $request = Request::create('/component-test-page');
        $request->setRouteResolver(fn () => $route);

        return app(ComponentDelivery::class)->published($page, [$this->block('NativeCard')], $request, 'site');
    }

    public function test_working_head_profile_and_published_artifact_advance_independently_of_the_calling_page(): void
    {
        $component = BeamUxEntry::create(['slug' => 'native-card', 'type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored', 'realm' => 'site']);
        $page = BeamUxEntry::create(['slug' => 'native-page', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site', 'segment' => '/native-page']);
        $publication = app(EntryPublication::class);
        $first = $publication->draftFromBase($component, $this->card('First pinned body'), null);
        $this->assertNull($component->fresh()->published_version);
        $this->assertNotNull($first->headVersion);
        $profile = app(ManifestProfileResolver::class)->resolve($page);
        $this->assertContains('NativeCard', array_column($profile['nodes'], 'name'));
        $pageState = $publication->recordDraft($page, [$this->block('NativeCard')]);
        try {
            $publication->publishVersion($page->fresh(), $pageState->headVersion);
            $this->fail('A never-published dependency must refuse page publication.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('same Release', $error->getMessage());
            $this->assertNull($page->fresh()->published_version);
        }
        $publication->publishVersions($page->fresh(), [$component->id => $first->headVersion, $page->id => $pageState->headVersion]);
        $pagePin = $page->fresh()->published_version;
        $store = app(EntryArtifactStore::class);
        $pageAddress = $store->version($page->fresh());
        $oldComponentAddress = $store->version($component->fresh());
        $oldModule = $store->read($component->fresh());
        $this->assertStringContainsString('First pinned body', $oldModule);
        $initialDelivery = $this->publicComponents($page->fresh());
        $this->assertCount(1, $initialDelivery);
        $this->assertSame($oldComponentAddress, $initialDelivery[0]->version);
        $this->assertNotNull($initialDelivery[0]->url);

        $draft = $publication->draftFromBase($component->fresh(), $this->card('Second pinned body'), $first->headVersion);
        $this->assertSame($oldComponentAddress, $store->version($component->fresh()));
        $this->assertSame($oldModule, $store->read($component->fresh()));
        $this->assertSame($oldComponentAddress, $this->publicComponents($page->fresh())[0]->version);
        $this->assertSame($this->card('Second pinned body'), app(AuthoredComponents::class)->working($component->fresh()));
        $publication->publishVersion($component->fresh(), $draft->headVersion);
        $this->assertNotSame($oldComponentAddress, $store->version($component->fresh()));
        $this->assertStringContainsString('Second pinned body', $store->read($component->fresh()));
        $this->assertSame($pagePin, $page->fresh()->published_version);
        $this->assertSame($pageAddress, $store->version($page->fresh()));
        $this->assertSame($store->version($component->fresh()), $this->publicComponents($page->fresh())[0]->version);
        $component->fresh()->forceFill(['access' => []])->save();
        $denied = $this->publicComponents($page->fresh());
        $this->assertNull($denied[0]->url, 'The same public gate must revoke component artifact delivery.');
        $this->assertNull($denied[0]->version);
    }

    public function test_component_migration_leaves_legacy_rows_unchanged_and_the_new_kind_is_nullable(): void
    {
        $legacy = BeamUxEntry::create(['slug' => 'legacy-source', 'type' => 'component', 'format' => 'tsx']);
        $before = $legacy->fresh()->getRawOriginal();
        $migration = require dirname(__DIR__).'/database/migrations/shared/add_component_kind_to_beam_ux_entries_table.php.stub';
        $migration->down();
        $migration->up();
        $after = $legacy->fresh()->getRawOriginal();
        $this->assertNull($after['component_kind']);
        unset($before['component_kind'], $after['component_kind']);
        $this->assertSame($before, $after);
        $this->assertSame('source', $legacy->fresh()->bodyKind()->value);
    }

    public function test_publish_checks_the_fixed_release_graph_even_when_current_working_heads_are_acyclic(): void
    {
        $a = BeamUxEntry::create(['slug' => 'native-a', 'type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored', 'realm' => 'site']);
        $b = BeamUxEntry::create(['slug' => 'native-b', 'type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored', 'realm' => 'site']);
        $body = fn (string $name, array $children = []) => [$this->block('div', $children) + ['component' => ['name' => $name, 'props' => []]]];
        $publication = app(EntryPublication::class);
        $firstA = $publication->draftFromBase($a, $body('NativeA'), null);
        $oldB = $publication->draftFromBase($b, $body('NativeB', [$this->block('NativeA')]), null);
        $publication->publishVersions($a->fresh(), [$a->id => $firstA->headVersion, $b->id => $oldB->headVersion]);
        $newB = $publication->draftFromBase($b->fresh(), $body('NativeB'), $oldB->headVersion);
        $newA = $publication->draftFromBase($a->fresh(), $body('NativeA', [$this->block('NativeB')]), $firstA->headVersion);
        $before = \Illuminate\Support\Facades\DB::table('beam_ux_entries')->orderBy('id')->get()->toJson();
        try {
            $publication->publishVersions($a->fresh(), [$a->id => $newA->headVersion, $b->id => $oldB->headVersion]);
            $this->fail('Combining historical Release members introduced a cycle.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('cycle', $error->getMessage());
            $this->assertSame($before, \Illuminate\Support\Facades\DB::table('beam_ux_entries')->orderBy('id')->get()->toJson());
        }
        $publication->publishVersions($a->fresh(), [$a->id => $newA->headVersion, $b->id => $newB->headVersion]);
        $this->assertSame($newA->headVersion, $a->fresh()->published_version);
        $this->assertSame($newB->headVersion, $b->fresh()->published_version);
    }

    public function test_layout_and_template_compile_real_unnamed_slot_modules_and_refuse_a_missing_hole(): void
    {
        foreach (['layout', 'template'] as $type) {
            $entry = BeamUxEntry::create(['slug' => 'native-'.$type, 'type' => $type, 'format' => 'tsx', 'realm' => 'site']);
            $publication = app(EntryPublication::class);
            $state = $publication->recordDraft($entry, [$this->block('div', [$this->block('Slot')])]);
            $this->assertNull($state->compileError);
            $this->assertNotNull(app(EntryArtifactStore::class)->readVersion($entry->fresh(), $state->headVersion));
            $before = $publication->state($entry->fresh())->headVersion;
            try {
                $publication->recordDraft($entry->fresh(), [$this->block('div')]);
                $this->fail('A blocks '.$type.' without its unnamed hole was saved.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('unnamed Slot', $error->getMessage());
                $this->assertSame($before, $publication->state($entry->fresh())->headVersion);
            }
        }
    }

    public function test_legacy_component_theme_tokens_with_no_decoded_source_never_invoke_the_compiler(): void
    {
        $compiler = new class implements EntryBodyCompiler
        {
            public int $calls = 0;

            public function handles(BeamUxEntry $entry): bool
            {
                return true;
            }

            public function compile(BeamUxEntry $entry, string $source): string
            {
                $this->calls++;
                throw new \LogicException('Empty source must never reach Node.');
            }
        };
        $this->app->instance(EntryBodyCompiler::class, $compiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $entry = BeamUxEntry::create(['slug' => 'legacy-token-data', 'type' => 'component', 'format' => 'tsx', 'realm' => 'site']);
        $body = ['canvas' => ['accent' => '#112233']];
        $publication = app(EntryPublication::class);
        $publication->write($entry, $body);
        $this->assertNull(app(CompileEntryBody::class)->forEntry($entry->fresh()));
        $publication->publishAuthoredBody($entry->fresh());
        $entry = $entry->fresh();
        $this->assertNotNull($entry->published_version);
        $this->assertFalse(app(EntryArtifactStore::class)->hasVersion($entry, $entry->published_version));
        $this->assertSame($body, app(AuthoredComponents::class)->working($entry));
        $this->assertSame(0, $compiler->calls);
    }
}
