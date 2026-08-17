<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Ux\Http\Controllers\BeamUxEntryBodyController;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `resolveEntry()`'s slug lookup — found live (splicewire's `/beam` page): `slug` alone is NOT globally
 * unique, only `(namespace, slug)` is (the migration's own unique index). A `theme`-namespaced entry and
 * a bare page entry legitimately share a slug (a host names both its theme tokens AND its page "beam").
 * Before this fix, `show`/`update` resolved by slug with no tiebreaker — an ambiguous slug silently
 * served/saved whichever row's uuid happened to sort first, not the page a host's route actually meant.
 */
class BeamUxEntryBodyControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_show_prefers_the_null_namespace_entry_when_a_slug_is_shared(): void
    {
        // The namespaced sibling — created FIRST, so an unqualified `first()` (no explicit order) would
        // pick it before the page entry on most drivers, reproducing the live bug's exact shape.
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'namespace' => 'theme']);
        $page = BeamUxEntry::create(['slug' => 'beam', 'type' => 'page', 'namespace' => null]);

        $controller = $this->app->make(BeamUxEntryBodyController::class);
        $response = $controller->show(new Request, 'beam');
        $data = json_decode($response->getContent(), true)['data'];

        $this->assertSame((string) $page->id, $data['id']);
        $this->assertSame('page', $data['type']);
        $this->assertNotSame((string) $theme->id, $data['id']);
    }

    public function test_show_still_resolves_an_unambiguous_namespaced_slug(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'hero', 'type' => 'component', 'namespace' => 'kit']);

        $controller = $this->app->make(BeamUxEntryBodyController::class);
        $response = $controller->show(new Request, 'hero');
        $data = json_decode($response->getContent(), true)['data'];

        $this->assertSame((string) $entry->id, $data['id']);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
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
            $table->string('segment')->nullable();
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
    }
}
