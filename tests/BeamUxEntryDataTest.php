<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Data\BeamUxEntryData;
use Splicewire\Beam\Ux\Data\BeamUxEntryInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Theme\ThemeResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Ticket 05 (theme-entries-and-authoring): the `BeamUxEntry` Frame resource's create-input
 * validation (`BeamUxEntryInputData`, the real `$resource->input::validateAndCreate()` seam
 * `ParticleFrameResourceHandler`/`ParticleController` both run) and the `afterWrite` per-kind
 * default-body hook (`BeamUxEntryData`).
 */
class BeamUxEntryDataTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('namespace')->nullable()->index();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->integer('nav_order')->nullable();
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

        Gate::define('ux.site.author', fn ($user = null) => true);
        Gate::define('ux.operator.author', fn ($user = null) => false);

        // The default WriteGate delegates to the Laravel gate; grant create so afterWrite()'s body
        // write passes (same precedent as BeamUxEntryTest).
        Gate::define('create', fn ($user = null) => true);

        // This isolated package fixture mounts no tenant middleware. Under the
        // conjunctive list-admission rule (integrator ruling 2026-10-10 02:43Z),
        // make its intended all-entry population explicit rather than borrowing
        // EntryFormPolicy::viewAny as a row boundary.
        app(ParticleResourceRegistry::class)->get('beam-ux-entry')->scope =
            static fn (Builder $query): Builder => $query->whereNotNull($query->getModel()->getQualifiedKeyName());
    }

    public function test_the_mounted_form_only_offers_writable_fields_and_keeps_declared_widgets(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::policy(BeamUxEntry::class, EntryFormPolicy::class);
        $schema = $this->getJson('/frame/resources/beam-ux-entry/schema')->assertOk()->json();
        $this->assertSame(['type', 'title', 'slug', 'realm', 'parentId', 'segment', 'navOrder'], array_keys($schema['properties']));
        $this->assertNotContains('id', $schema['required']);
        $this->assertSame(BeamUxEntryInputData::CREATABLE_TYPES, $schema['properties']['type']['enum']);
        $this->assertSame('combobox', $schema['properties']['realm']['x-stud-widget']);
        $this->assertArrayHasKey('x-stud-resource-ref', $schema['properties']['parentId']);
    }

    public function test_mounted_create_generates_identity_and_body_and_edit_preserves_them(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::policy(BeamUxEntry::class, EntryFormPolicy::class);
        $input = ['type' => 'page', 'title' => 'New page', 'slug' => 'new-page', 'realm' => 'site'];
        $data = $this->postJson('/frame/resources/beam-ux-entry', $input)->assertSuccessful()->json('data');
        $entry = BeamUxEntry::findOrFail($data['id']);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($entry->id));
        $this->assertSame('', $entry->namespace);
        $particleId = $entry->particle_id;
        $this->assertNotNull($particleId);
        $driver = app(StorageDriverResolver::class)->resolve($entry);
        $this->assertSame([], $driver->read($particleId)?->body);
        $entry->namespace = 'docs';
        $entry->saveQuietly();
        $body = [['type' => 'text', 'text' => 'Keep this authored body']];
        Gate::policy(\Splicewire\Beam\Models\BeamParticle::class, EntryFormPolicy::class);
        $driver->write($particleId, $body, $entry->namespace);
        $this->getJson('/frame/resources/beam-ux-entry/records/'.$entry->id)->assertOk()
            ->assertJsonPath('data.type', 'page')->assertJsonPath('data.title', 'New page');
        $this->putJson('/frame/resources/beam-ux-entry/records/'.$entry->id, [...$input, 'title' => 'Edited page'])->assertSuccessful();
        $entry->refresh();
        $this->assertSame('Edited page', $entry->title);
        $this->assertSame($particleId, $entry->particle_id);
        $this->assertSame('docs', $entry->namespace);
        $this->assertSame($body, $driver->read($particleId)?->body);
    }

    #[DataProvider('inputMapperConfigurations')]
    public function test_mounted_create_validates_the_documented_parent_id_on_every_host_shape(?string $inputMapper): void
    {
        $this->useInputMapper($inputMapper);
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::policy(BeamUxEntry::class, EntryFormPolicy::class);

        $this->postJson('/frame/resources/beam-ux-entry', [
            'type' => 'page',
            'title' => 'Child',
            'slug' => 'child',
            'realm' => 'site',
            'parentId' => 'missing-id',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parentId'])
            ->assertJsonPath('errors.parentId.0', 'The parent id field must be a valid UUID.');
    }

    #[DataProvider('inputMapperConfigurations')]
    public function test_mounted_create_validates_the_documented_nav_order_on_every_host_shape(?string $inputMapper): void
    {
        $this->useInputMapper($inputMapper);
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::policy(BeamUxEntry::class, EntryFormPolicy::class);

        $schema = $this->getJson('/frame/resources/beam-ux-entry/schema')->assertOk()->json();
        $this->assertArrayHasKey('navOrder', $schema['properties']);
        $this->assertArrayNotHasKey('nav_order', $schema['properties']);

        $response = $this->postJson('/frame/resources/beam-ux-entry', [
            'type' => 'page',
            'title' => 'Child',
            'slug' => 'child',
            'realm' => 'site',
            'navOrder' => 'abc',
        ])->assertUnprocessable()->assertJsonValidationErrors(['navOrder']);

        $this->assertSame(['navOrder'], array_keys($response->json('errors')));
    }

    #[DataProvider('inputMapperConfigurations')]
    public function test_mounted_create_rejects_the_retired_snake_case_wire_names(?string $inputMapper): void
    {
        $this->useInputMapper($inputMapper);
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::policy(BeamUxEntry::class, EntryFormPolicy::class);

        $this->postJson('/frame/resources/beam-ux-entry', [
            'type' => 'page',
            'title' => 'Child',
            'slug' => 'child',
            'realm' => 'site',
            'parent_id' => '00000000-0000-4000-8000-000000000000',
            'nav_order' => 3,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id', 'nav_order']);
    }

    public function test_mounted_create_scopes_segment_uniqueness_to_the_mapped_parent_id(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::policy(BeamUxEntry::class, EntryFormPolicy::class);
        $parent = BeamUxEntry::create(['namespace' => '', 'slug' => 'docs', 'type' => UxType::Page]);
        BeamUxEntry::create([
            'namespace' => '',
            'slug' => 'existing',
            'type' => UxType::Page,
            'parent_id' => $parent->id,
            'segment' => 'guide',
        ]);

        $this->postJson('/frame/resources/beam-ux-entry', [
            'type' => 'page',
            'title' => 'Another guide',
            'slug' => 'another-guide',
            'realm' => 'site',
            'parentId' => $parent->id,
            'segment' => 'guide',
        ])->assertUnprocessable()->assertJsonValidationErrors(['segment']);
    }

    public function test_mounted_edit_projects_enum_and_untitled_existing_entry_without_read_only_fields(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::policy(BeamUxEntry::class, EntryFormPolicy::class);
        $entry = BeamUxEntry::create(['type' => UxType::Page, 'slug' => 'untitled', 'realm' => 'site', 'namespace' => 'docs', 'title' => null, 'segment' => 'untitled', 'nav_order' => 4]);
        $data = $this->getJson('/frame/resources/beam-ux-entry/records/'.$entry->id)->assertOk()->json('data');
        $this->assertSame('page', $data['type']);
        $this->assertSame('', $data['title']);
        $this->assertSame('untitled', $data['segment']);
        $this->assertSame(4, $data['navOrder']);
        $this->assertSame($entry->id, $data['id']);
        $this->assertArrayNotHasKey('namespace', $data);
    }

    public function test_it_accepts_page_component_and_theme_but_rejects_layout_and_template(): void
    {
        foreach (BeamUxEntryInputData::CREATABLE_TYPES as $type) {
            $data = BeamUxEntryInputData::validateAndCreate([
                'type' => $type,
                'title' => 'Title',
                'slug' => 'slug-'.$type,
                'realm' => 'site',
            ]);
            $this->assertSame($type, $data->type);
        }

        foreach (['layout', 'template'] as $type) {
            try {
                BeamUxEntryInputData::validateAndCreate([
                    'type' => $type,
                    'title' => 'Title',
                    'slug' => 'slug-'.$type,
                    'realm' => 'site',
                ]);
                $this->fail("Expected a ValidationException for type [{$type}].");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('type', $e->errors());
            }
        }
    }

    public function test_creating_a_duplicate_namespace_slug_pair_returns_a_validation_exception(): void
    {
        BeamUxEntry::create(['namespace' => '', 'slug' => 'about', 'type' => UxType::Page]);

        $this->expectException(ValidationException::class);

        BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'About',
            'slug' => 'about',
            'realm' => 'site',
        ]);
    }

    public function test_a_realm_the_author_is_not_entitled_to_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'Ops',
            'slug' => 'ops',
            'realm' => 'operator',
        ]);
    }

    public function test_the_input_omits_namespace_and_prepare_defaults_only_new_entries(): void
    {
        $data = BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'Home',
            'slug' => 'home',
            'realm' => 'site',
        ]);

        $this->assertArrayNotHasKey('namespace', $data->toModelAttributes());

        $entry = new BeamUxEntry;
        BeamUxEntryData::prepare($entry, $data);
        $entry->fill($data->toModelAttributes())->save();
        $this->assertSame('', $entry->refresh()->namespace);

        foreach (['guides', null] as $namespace) {
            $existing = BeamUxEntry::create([
                'namespace' => $namespace,
                'slug' => 'existing-'.($namespace ?? 'null'),
                'type' => UxType::Page,
            ]);
            BeamUxEntryData::prepare($existing, $data);
            $existing->fill($data->toModelAttributes())->save();
            $this->assertSame($namespace, $existing->refresh()->namespace);
        }
    }

    public function test_after_write_seeds_page_and_component_with_an_empty_blockdoc_body(): void
    {
        foreach ([UxType::Page, UxType::Component] as $type) {
            $entry = BeamUxEntry::create(['namespace' => '', 'slug' => 'x-'.$type->value, 'type' => $type]);

            BeamUxEntryData::afterWrite($entry, null);

            $this->assertNotNull($entry->particle_id);
            $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)?->body;
            $this->assertSame([], $body);

            // The RAW stored JSON is `[]` (a blockdoc JsonDoc — JsonNode[] — is a genuine list; unlike
            // Puck's retired {root,content,zones} shape there is no object/array ambiguity to guard).
            $raw = (string) DB::table('beam_particles')->where('id', $entry->particle_id)->value('payload');
            $this->assertJsonStringEqualsJsonString('[]', $raw);
        }
    }

    public function test_segment_and_nav_order_round_trip_through_the_console_form(): void
    {
        // theme-entries-and-authoring provenance sweep, ux-demo-convergence 2026-09-12: no owner
        // ruling ever deferred these two columns; the console form simply never carried them, while
        // NavProjector has always read both live off the row.
        $data = BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'Songs',
            'slug' => 'songs',
            'realm' => 'site',
            'segment' => '/songs',
            'navOrder' => 30,
        ]);

        $this->assertSame('/songs', $data->segment);
        $this->assertSame(30, $data->navOrder);
        $this->assertSame('/songs', $data->toModelAttributes()['segment']);
        $this->assertSame(30, $data->toModelAttributes()['nav_order']);
    }

    public function test_segment_and_nav_order_are_optional_and_default_null(): void
    {
        $data = BeamUxEntryInputData::validateAndCreate([
            'type' => 'component',
            'title' => 'Hero block',
            'slug' => 'hero-block',
            'realm' => 'site',
        ]);

        $this->assertNull($data->segment);
        $this->assertNull($data->navOrder);
        $this->assertNull($data->toModelAttributes()['segment']);
        $this->assertNull($data->toModelAttributes()['nav_order']);
    }

    public function test_two_pass_through_siblings_may_share_a_null_segment(): void
    {
        // The documented shape (ContainmentTest::test_unplaced_page_entries_without_a_segment_are_excluded_from_nav):
        // several segment-less entries under the same parent must not collide.
        BeamUxEntry::create(['namespace' => '', 'slug' => 'about', 'type' => UxType::Page, 'segment' => null]);

        $data = BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'FAQ',
            'slug' => 'faq',
            'realm' => 'site',
            'segment' => null,
        ]);

        $this->assertNull($data->segment);
    }

    public function test_a_segment_already_taken_by_a_sibling_under_the_same_parent_is_rejected(): void
    {
        $parent = BeamUxEntry::create(['namespace' => '', 'slug' => 'blog', 'type' => UxType::Page, 'segment' => '/blog']);
        BeamUxEntry::create(['namespace' => '', 'slug' => 'existing', 'type' => UxType::Page, 'segment' => 'first-post', 'parent_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'Second post',
            'slug' => 'second-post',
            'realm' => 'site',
            'segment' => 'first-post',
            'parentId' => $parent->id,
        ]);
    }

    public function test_the_same_segment_is_permitted_under_a_different_parent(): void
    {
        $blogA = BeamUxEntry::create(['namespace' => '', 'slug' => 'blog-a', 'type' => UxType::Page, 'segment' => '/blog-a']);
        $blogB = BeamUxEntry::create(['namespace' => '', 'slug' => 'blog-b', 'type' => UxType::Page, 'segment' => '/blog-b']);
        BeamUxEntry::create(['namespace' => '', 'slug' => 'a-post', 'type' => UxType::Page, 'segment' => 'intro', 'parent_id' => $blogA->id]);

        $data = BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'Intro',
            'slug' => 'b-intro',
            'realm' => 'site',
            'segment' => 'intro',
            'parentId' => $blogB->id,
        ]);

        $this->assertSame('intro', $data->segment);
    }

    public function test_the_display_data_class_hydrates_segment_and_nav_order_off_the_model(): void
    {
        // The list/read projection retains placement values independently of the input form.
        $entry = BeamUxEntry::create([
            'namespace' => '',
            'slug' => 'about',
            'type' => UxType::Page,
            'segment' => '/about',
            'nav_order' => 20,
        ]);

        $data = BeamUxEntryData::from($entry);

        $this->assertSame('/about', $data->segment);
        $this->assertSame(20, $data->navOrder);
        $this->assertArrayHasKey('navOrder', $data->toArray());
        $this->assertArrayNotHasKey('nav_order', $data->toArray());
    }

    public function test_after_write_seeds_a_theme_entry_with_the_currently_resolved_theme(): void
    {
        $entry = BeamUxEntry::create(['namespace' => '', 'slug' => 'default', 'type' => UxType::Theme]);

        BeamUxEntryData::afterWrite($entry, null);

        $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)?->body;

        $this->assertSame(app(ThemeResolver::class)->resolve(), $body);
        // Not blank — a real starting point (the resolved defaults), never an empty {canvas:{},...}.
        $this->assertSame('#14803f', $body['canvas']['accent']);
    }

    /** @return array<string, array{class-string|null}> */
    public static function inputMapperConfigurations(): array
    {
        return [
            'no global input mapper' => [null],
            'global camel-case input mapper' => [CamelCaseMapper::class],
        ];
    }

    /** @param class-string|null $inputMapper */
    private function useInputMapper(?string $inputMapper): void
    {
        config()->set('data.name_mapping_strategy.input', $inputMapper);
        app(DataConfig::class)->reset();
    }
}

class EntryFormPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return true;
    }
}
