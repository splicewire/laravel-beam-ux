<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Splicewire\Beam\Media\BeamMediaServiceProvider;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Ux\Media\Data\EntryImageUploadData;
use Splicewire\Beam\Ux\Media\EntryMedia;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EntryMediaTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MediaLibraryServiceProvider::class, BeamMediaServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('media-library.media_model', Media::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
            $table->string('type')->default('page');
            $table->string('format')->default('tsx');
            $table->string('realm')->default('site');
            $table->string('residency_mode')->default('context-following');
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $source = dirname((new \ReflectionClass(BeamMediaServiceProvider::class))->getFileName()).'/../database/migrations/shared/create_media_table.php.stub';
        (require $source)->up();
        Storage::fake('public');
        config(['media-library.media_model' => Media::class, 'media-library.disk_name' => 'public', 'media-library.queue_conversions_by_default' => false]);
        Gate::define('entitlement:ux.author', fn ($user) => $user->author ?? false);
        Gate::policy(BeamUxEntry::class, EntryMediaParentPolicy::class);
    }

    private function entry(string $slug = 'editable'): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $slug, 'type' => 'page']);
    }

    private function actor(bool $author = true): GenericUser
    {
        return new GenericUser(['id' => 'author', 'author' => $author]);
    }

    public function test_optional_host_registers_typed_entry_operations_and_upload_stays_on_that_entry(): void
    {
        $ops = app(ParticleOperationRegistry::class);
        $this->assertTrue($ops->has('beam-ux-entry', 'media'));
        $this->assertTrue($ops->has('beam-ux-entry', 'upload-image'));
        $entry = $this->entry();
        $other = $this->entry('other');
        $actor = $this->actor();
        $service = app(EntryMedia::class);
        $request = \Illuminate\Http\Request::create('/beam-ux-entries/'.$entry->id.'/upload-image', 'POST', [], [], ['file' => UploadedFile::fake()->image('photo.png', 4, 4)]);
        $image = \Splicewire\Beam\Ux\Media\Particle\EntryImageUploadOp::handle($entry, $request, $actor);
        $row = Media::findOrFail($image->id);
        $this->assertSame($entry->id, $row->model_id);
        $this->assertSame(\Splicewire\Beam\Ux\Media\EntryMediaOwner::MORPH, $row->model_type);
        $this->assertInstanceOf(\Splicewire\Beam\Ux\Media\EntryMediaOwner::class, $row->model);
        $this->assertSame(BeamUxEntry::class, \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($entry->getMorphClass()));
        $this->assertSame('entry-images', $row->collection_name);
        $this->assertSame(['id', 'name', 'url'], array_keys($image->toArray()));
        Storage::disk('public')->assertExists($row->getPathRelativeToRoot());
        $this->assertSame([$image->id], array_map(fn ($item) => $item->id, $service->library($entry, $actor)->items));
        $this->assertSame([], $service->library($other, $actor)->items);
        $this->assertSame(2, DB::table('beam_ux_entries')->count(), 'Upload must not create an owner entry or draft.');
    }

    public function test_http_multipart_upload_and_list_require_the_author_ability_and_parent_update(): void
    {
        \Splicewire\Beam\Facades\Particle::ops('beam-ux-entries', 'beam-ux-entry', ['media', 'upload-image']);
        $entry = $this->entry();
        $url = '/beam-ux-entries/'.$entry->id;
        $this->actingAs($this->actor(false));
        $this->getJson($url.'/media')->assertForbidden();
        $this->post($url.'/upload-image', ['file' => UploadedFile::fake()->image('denied.png')], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, Media::count());
        $this->actingAs($this->actor());
        $upload = $this->post($url.'/upload-image', ['file' => UploadedFile::fake()->image('photo.png')], ['Accept' => 'application/json'])->assertOk();
        $id = $upload->json('data.id');
        $this->assertNotEmpty($id);
        $this->getJson($url.'/media')->assertOk()->assertJsonPath('data.items.0.id', $id);
        $locked = $this->entry('locked');
        $this->getJson('/beam-ux-entries/'.$locked->id.'/media')->assertForbidden();
        $this->post('/beam-ux-entries/'.$locked->id.'/upload-image', ['file' => UploadedFile::fake()->image('denied.png')], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(1, Media::count());
    }

    public function test_body_envelope_does_not_offer_media_to_a_non_author(): void
    {
        $entry = $this->entry();
        $this->actingAs($this->actor(false));
        $this->assertFalse(app(\Splicewire\Beam\Ux\Particle\EntryBodyEnvelope::class)->of($entry, [])->mediaLibrary);
        $this->actingAs($this->actor());
        $this->assertTrue(app(\Splicewire\Beam\Ux\Particle\EntryBodyEnvelope::class)->of($entry, [])->mediaLibrary);
    }

    public function test_detected_raster_bytes_never_keep_a_client_html_extension(): void
    {
        $path = $this->ownedFileFixtureRoot().'/polyglot';
        file_put_contents($path, 'GIF89a;<html><script>alert(1)</script></html>');
        $image = app(EntryMedia::class)->upload($this->entry(), new UploadedFile($path, 'x.html', null, null, true), $this->actor());
        $row = Media::findOrFail($image->id);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}\\.gif$/', $row->file_name);
        $this->assertStringNotContainsString('.html', $image->url);
        $this->assertSame('image/gif', $row->mime_type);
    }

    public function test_soft_delete_keeps_images_for_restore_and_force_delete_removes_files(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableUuidMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
        config(['beam.taxonomy.models.tag' => Fixtures\Tag::class]);
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });
        Schema::create('taggables', function (Blueprint $table) {
            $table->uuid('tag_id');
            $table->uuid('taggable_id');
            $table->string('taggable_type');
        });
        $entry = $this->entry();
        $image = app(EntryMedia::class)->upload($entry, UploadedFile::fake()->image('photo.png'), $this->actor());
        $path = Media::findOrFail($image->id)->getPathRelativeToRoot();
        $entry->delete();
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull(Media::find($image->id));
        $entry->restore();
        $this->assertCount(1, app(EntryMedia::class)->library($entry, $this->actor())->items);
        $entry->forceDelete();
        $this->assertNull(Media::find($image->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_library_is_bounded_to_fifty_addressed_images(): void
    {
        $entry = $this->entry();
        for ($i = 0; $i < 51; $i++) {
            Media::create(['model_type' => \Splicewire\Beam\Ux\Media\EntryMediaOwner::MORPH, 'model_id' => $entry->id,
                'collection_name' => 'entry-images', 'name' => 'Photo '.$i, 'file_name' => $i.'.png', 'mime_type' => 'image/png',
                'disk' => 'public', 'size' => 1, 'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => []]);
        }
        $this->assertCount(50, app(EntryMedia::class)->library($entry, $this->actor())->items);
    }

    public function test_both_ux_author_and_the_addressed_parent_update_policy_are_required(): void
    {
        $service = app(EntryMedia::class);
        foreach ([[$this->entry(), $this->actor(false)], [$this->entry('locked'), $this->actor()], [$this->entry('guest'), null]] as [$entry, $actor]) {
            foreach (['library', 'upload'] as $method) {
                try {
                    $method === 'upload' ? $service->upload($entry, UploadedFile::fake()->image('photo.png'), $actor) : $service->library($entry, $actor);
                    $this->fail('A refused parent/author was admitted.');
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode());
                }
            }
        }
        $this->assertSame(0, Media::count());
    }

    public function test_svg_disguised_html_remote_inputs_and_oversize_are_refused_without_media_writes(): void
    {
        $entry = $this->entry();
        $service = app(EntryMedia::class);
        foreach ([UploadedFile::fake()->createWithContent('photo.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->createWithContent('photo.png', '<html>not an image</html>'),
            UploadedFile::fake()->image('large.png')->size(8193)] as $file) {
            try {
                $service->upload($entry, $file, $this->actor());
                $this->fail('Invalid raster admitted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
        foreach ([['file' => 'https://example.test/image.png'], ['file' => UploadedFile::fake()->image('a.png'), 'model_id' => 'foreign'],
            ['file' => UploadedFile::fake()->image('a.png'), 'disk' => 'private'], ['file' => UploadedFile::fake()->image('a.png'), 'model_type' => 'App\\User']] as $input) {
            try {
                EntryImageUploadData::validateAndCreate($input);
                $this->fail('Unowned input admitted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(0, Media::count());
    }
}

class EntryMediaParentPolicy
{
    public function update($actor, BeamUxEntry $entry): bool
    {
        return $entry->slug !== 'locked';
    }
}
