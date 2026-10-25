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
