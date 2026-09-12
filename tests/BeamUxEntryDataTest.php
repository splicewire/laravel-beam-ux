<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Facades\Beam;
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
            'nav_order' => 30,
        ]);

        $this->assertSame('/songs', $data->segment);
        $this->assertSame(30, $data->nav_order);
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
        $this->assertNull($data->nav_order);
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
            'parent_id' => $parent->id,
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
            'parent_id' => $blogB->id,
        ]);

        $this->assertSame('intro', $data->segment);
    }

    public function test_after_write_seeds_a_theme_entry_with_the_currently_resolved_theme(): void
    {
        $entry = BeamUxEntry::create(['namespace' => '', 'slug' => 'default', 'type' => UxType::Theme]);

        BeamUxEntryData::afterWrite($entry, null);

        $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)?->body;

        $this->assertSame(app(ThemeResolver::class)->resolve(), $body);
        // Not blank — a real starting point (the resolved defaults), never an empty {canvas:{},...}.
        $this->assertSame('#4F7CFF', $body['canvas']['accent']);
    }
}
