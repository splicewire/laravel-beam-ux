<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Doctor\BeamUxRedirectAudit;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * ADR-0236 d6, d9 and Consequences (EP-30 slice 3): the doctor names a Redirect that dangles (its target deleted, gone,
 * or hidden with nothing further), one that loops or runs past the step bound, one at a URL a page answers, and one a
 * host route registered before the renderer serves first. It takes the renderer's own walk as a guest, so what it names
 * is what a guest gets. Deleting a page leaves its Redirects in place and the doctor names them; restoring the page
 * brings them back.
 */
class RedirectAuditTest extends AuthoredComponentNativeTestCase
{
    private BeamUxEntry $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return response()->json(['props' => $props]);
            }
        });
        // A host route registered before the renderer, as a host's own legacy handler would be.
        Route::get('legacy', fn () => 'legacy')->name('legacy');
        Route::beamUxSite('test', withNav: false);
        $this->pricing = $this->livePage('pricing');
    }

    public function test_redirects_that_reach_their_targets_pass(): void
    {
        $this->redirect('/plans', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->redirect('/one', 'path', ['target_path' => '/plans']);
        $this->redirect('/out', 'address', ['target_url' => 'https://example.com']);
        $this->redirect('/sign-in-old', 'path', ['target_path' => '/login']);

        foreach ($this->audit() as $finding) {
            $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->check.': '.$finding->detail);
        }
    }

    public function test_it_names_a_dangling_redirect_a_loop_a_page_shadowed_one_and_a_route_shadowed_one(): void
    {
        $this->redirect('/to-nowhere', 'page', ['target_entry_id' => (string) Str::uuid()]);
        $this->redirect('/to-a-record', 'record', ['target_collection' => 'posts', 'target_record_id' => 'hello']);
        $this->redirect('/loop-a', 'path', ['target_path' => '/loop-b']);
        $this->redirect('/loop-b', 'path', ['target_path' => '/loop-a']);
        for ($i = 0; $i < 6; $i++) {
            $this->redirect("/hop-{$i}", 'path', ['target_path' => '/hop-'.($i + 1)]);
        }
        $this->redirect('/hop-6', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->redirect('/pricing', 'path', ['target_path' => '/elsewhere']);
        $this->redirect('/legacy', 'page', ['target_entry_id' => $this->pricing->id]);

        $findings = $this->audit();

        $dangling = $findings[BeamUxRedirectAudit::DANGLING];
        $this->assertSame(DoctorStatus::Warn, $dangling->status);
        $this->assertStringContainsString('/to-nowhere', $dangling->detail);
        $this->assertStringContainsString('/to-a-record', $dangling->detail);
        $this->assertStringContainsString('posts:hello', $dangling->detail);

        $loops = $findings[BeamUxRedirectAudit::LOOPS];
        $this->assertSame(DoctorStatus::Warn, $loops->status);
        $this->assertStringContainsString('/loop-a', $loops->detail);
        $this->assertStringContainsString('/hop-0', $loops->detail);
        $this->assertStringNotContainsString('/hop-2:', $loops->detail, 'five steps from /hop-2 reach the page');

        $page = $findings[BeamUxRedirectAudit::PAGE_SHADOWED];
        $this->assertSame(DoctorStatus::Warn, $page->status);
        $this->assertStringContainsString('/pricing', $page->detail);

        $route = $findings[BeamUxRedirectAudit::ROUTE_SHADOWED];
        $this->assertSame(DoctorStatus::Warn, $route->status);
        $this->assertStringContainsString('/legacy', $route->detail);
        $this->assertStringContainsString('legacy', $route->detail);
    }

    public function test_a_redirect_to_a_hidden_page_with_nothing_further_dangles(): void
    {
        $retired = $this->livePage('retired');
        $retired->forceFill(['workflow_marking' => 'review'])->save();
        $this->app->bind(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class, fn () => new class implements \Splicewire\Beam\Ux\Sitemap\EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->workflow_marking !== 'review';
            }
        });
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Http\PublicEntryGate::class);
        $this->redirect('/old-retired', 'page', ['target_entry_id' => $retired->id]);

        $this->assertStringContainsString('/old-retired', $this->audit()[BeamUxRedirectAudit::DANGLING]->detail);

        // d9: a hidden page's URL is looked up in turn, so a Redirect leading on from there is no defect.
        $this->redirect('/retired', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->assertSame(DoctorStatus::Pass, $this->audit()[BeamUxRedirectAudit::DANGLING]->status);
    }

    public function test_deleting_a_page_strands_its_redirects_and_restoring_it_brings_them_back(): void
    {
        $this->redirect('/plans', 'page', ['target_entry_id' => $this->pricing->id]);
        // An entry's delete detaches its tags, so the host binds a tag model and its tables (EntryLifecycleTest's precedent).
        config()->set('beam.taxonomy.models.tag', Fixtures\Tag::class);
        \Illuminate\Support\Facades\Schema::create('tags', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 512);
            $table->string('slug', 512);
            $table->string('type')->nullable();
            $table->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('taggables', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('tag_id');
            $table->uuid('taggable_id');
            $table->string('taggable_type');
        });

        $this->pricing->delete();

        $this->get('/plans')->assertNotFound();
        $this->assertSame(1, BeamUxRedirect::query()->count(), 'd6: a delete leaves the Redirect in place');
        $dangling = $this->audit()[BeamUxRedirectAudit::DANGLING];
        $this->assertSame(DoctorStatus::Warn, $dangling->status);
        $this->assertStringContainsString('/plans', $dangling->detail);
        $this->assertStringContainsString('deleted', $dangling->detail);

        BeamUxEntry::withTrashed()->findOrFail($this->pricing->id)->restore();

        $this->get('/plans')->assertStatus(301)->assertRedirect('/pricing');
        $this->assertSame(DoctorStatus::Pass, $this->audit()[BeamUxRedirectAudit::DANGLING]->status);
    }

    /** @return array<string, Finding> */
    private function audit(): array
    {
        return collect(app(BeamUxRedirectAudit::class)->run())->keyBy('check')->all();
    }

    private function redirect(string $path, string $kind, array $columns): void
    {
        // Straight into the table, as rows a Release, an import or an older save could leave.
        \Illuminate\Support\Facades\DB::table('beam_ux_redirects')->insert($columns + [
            'id' => (string) Str::uuid(), 'realm' => 'site', 'path' => $path, 'match' => 'exact', 'target_kind' => $kind,
            'permanent' => true, 'origin' => 'author', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function livePage(string $segment): BeamUxEntry
    {
        $page = BeamUxEntry::create(['slug' => $segment, 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => $segment]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [['kind' => 'block', 'name' => 'div', 'isComponent' => false,
            'dynamic' => false, 'props' => [], 'children' => []]])->headVersion);

        return $page->fresh();
    }
}
