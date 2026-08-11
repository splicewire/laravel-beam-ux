<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Beam;
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
            $table->timestamps();
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

        Gate::define('author-ux-site', fn ($user = null) => true);
        Gate::define('author-ux-operator', fn ($user = null) => false);

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

    public function test_to_model_attributes_auto_derives_an_empty_namespace(): void
    {
        $data = BeamUxEntryInputData::validateAndCreate([
            'type' => 'page',
            'title' => 'Home',
            'slug' => 'home',
            'realm' => 'site',
        ]);

        $this->assertSame('', $data->toModelAttributes()['namespace']);
    }

    public function test_after_write_seeds_page_and_component_with_an_empty_puck_body(): void
    {
        foreach ([UxType::Page, UxType::Component] as $type) {
            $entry = BeamUxEntry::create(['namespace' => '', 'slug' => 'x-'.$type->value, 'type' => $type]);

            BeamUxEntryData::afterWrite($entry, null);

            $this->assertNotNull($entry->particle_id);
            $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)?->body;
            $this->assertSame(['root' => [], 'content' => [], 'zones' => []], $body);

            // The RAW stored JSON must have root/zones as OBJECTS ({}), not arrays ([]) — Puck's
            // Data shape ({root: {}, content: [], zones: {}}); PHP's decode-to-array collapses this
            // distinction on read, so assert against the raw column string directly.
            $raw = (string) DB::table('beam_particles')->where('id', $entry->particle_id)->value('payload');
            $this->assertJsonStringEqualsJsonString('{"root":{},"content":[],"zones":{}}', $raw);
        }
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
