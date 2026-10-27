<?php
namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Data\SiteHealthRowData;
use Splicewire\Beam\Ux\Data\BeamUxEntryData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class SiteHealthRowDataTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Rushing\DataFilters\ServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_one_row_shows_site_indexing_and_saved_mirror_state(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'about', 'type' => 'page', 'namespace' => null,
            'realm' => 'site', 'realms' => ['site'], 'segment' => 'about',
        ]);
        $row = SiteHealthRowData::project($entry);
        $this->assertSame((string) $entry->id, $row->id);
        $this->assertTrue($row->indexed);
        $this->assertSame('not-yet-saved', $row->state);
        $this->assertFalse($row->exists);
        $this->assertNull($row->path);
        $this->assertNotNull($row->url);
    }

    public function test_the_entry_read_projection_carries_only_public_page_urls(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'about', 'title' => 'About', 'type' => 'page', 'namespace' => null,
            'realm' => 'site', 'realms' => ['site'], 'segment' => 'about',
        ]);
        $this->assertSame('/about', BeamUxEntryData::project($entry)->url);
        $component = BeamUxEntry::create(['slug' => 'card', 'type' => 'component', 'realm' => 'site']);
        $this->assertNull(BeamUxEntryData::project($component)->url);
    }

    public function test_health_is_a_read_only_diagnostic_declaration_with_a_native_filter_query(): void
    {
        $registry = $this->app->make(\Splicewire\Beam\Particle\ParticleResourceRegistry::class);
        $this->assertTrue($registry->has('beam-ux-site-health'));
        $resource = $registry->get('beam-ux-site-health');
        $this->assertTrue($resource->readOnly);
        $this->assertSame(\Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility::NAME, $resource->policy);
        $this->assertSame(\Splicewire\Beam\Ux\Diagnostics\DiagnosticsReadPolicy::class, $resource->readPolicy);
        $this->assertTrue($this->app->make(\Rushing\DataFilters\Registry\ResourceRegistry::class)->has('beam-ux-site-health'));
        $this->assertTrue($registry->has('beam-ux-sitemap-health'));
        $this->assertTrue($registry->has('beam-ux-mirror-status'));
        $this->assertSame([], $registry->realmsFor('beam-ux-site-health'), 'the UX seat is separate U09 work');
        $decision = $this->app->make(\Splicewire\Beam\Ux\Diagnostics\DiagnosticsReadPolicy::class)
            ->inspect(null, $resource, \Illuminate\Http\Request::create('/'));
        $this->assertTrue($decision->denied());
    }

    public function test_non_site_pages_have_no_view_url_and_are_not_indexed(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'register', 'type' => 'page', 'realm' => 'auth',
            'realms' => ['auth'], 'segment' => 'register',
        ]);
        $this->assertNull(BeamUxEntryData::project($entry)->url);
        $row = SiteHealthRowData::project($entry);
        $this->assertFalse($row->indexed);
        $this->assertFalse($row->routable);
        $this->assertNull($row->url);
        $this->assertSame('not-yet-saved', $row->state);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
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
            // Nullable here (unlike the real migration's `->default('site')`, never `.nullable()`) so
            // the "no realm membership" fixture below can override the model's PHP-level default.
            $table->string('realm')->nullable()->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}
