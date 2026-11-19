<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Splicewire\Beam\Media\BeamMediaServiceProvider;
use Splicewire\Beam\Media\Models\Media;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Support\EditorRoutes;

/**
 * particle-editor G8, the conditional op: EditorRoutes::mount adds `upload-image` only when
 * EntryMedia::available() — i.e. when a host has registered beam-media. This testbench registers
 * BeamMediaServiceProvider (as {@see EntryMediaTest} does), so the op mounts here; the absent case is
 * pinned in {@see EditorRoutesG8MountTest}. RED before the EditorRoutes op-list change: even with media
 * available, the op list never names upload-image, so the route is not mounted.
 */
class EditorRoutesG8UploadImageMountTest extends TestCase
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
        Gate::define('entitlement:ux.author', fn (?User $user = null) => $user instanceof G8MediaAuthor);
        EditorRoutes::mount();
    }

    /** @return list<string> */
    private function routeUris(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->values()->all();
    }

    private function hitUploadImage(string $id): TestResponse
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'beam-ux-entries/{id}/upload-image');
        $this->assertNotNull($route, 'route beam-ux-entries/{id}/upload-image is not mounted');
        $method = in_array('GET', $route->methods(), true) ? 'GET' : 'POST';

        return $this->json($method, "/beam-ux-entries/{$id}/upload-image");
    }

    public function test_upload_image_op_mounts_when_entry_media_is_available_and_is_gated_by_ux_author(): void
    {
        $this->assertTrue(\Splicewire\Beam\Ux\Media\EntryMedia::available(), 'precondition: entry media is registered');
        $this->assertContains('beam-ux-entries/{id}/upload-image', $this->routeUris());

        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page']);

        // An author passes the gate and reaches the op; an empty body fails its typed input validation (422),
        // proving the route reaches EntryImageUploadOp rather than 403-ing or 404-ing.
        $this->actingAs(new G8MediaAuthor);
        $this->hitUploadImage($entry->id)->assertStatus(422);

        $this->actingAs(new User);
        $this->hitUploadImage($entry->id)->assertForbidden();
    }
}

class G8MediaAuthor extends User
{
    protected $table = 'users';
}
