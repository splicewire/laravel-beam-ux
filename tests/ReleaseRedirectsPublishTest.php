<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseReadiness;
use Splicewire\Beam\Ux\Release\ReleaseService;

/**
 * ADR-0236 d2, d4 (EP-31 slice 1; rulings 1 and 4): Publish leaves the Redirects its plan names. Outside a Release
 * (a save-body call, a seed, an import) they carry the `publish` origin. A Release's carry its id, `release` for a
 * move and `takedown` for a Take-down's target, and each records its causer. A Take-down's target must be live once
 * the Release publishes: published now or by this Release, not taken down by it, and not under a page hidden after it.
 * An exact Redirect already at a URL a move takes away now leads to the page that left it.
 */
class ReleaseRedirectsPublishTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        Gate::before(fn () => true);
        // A Release resolves its actor by id through the user provider.
        \Illuminate\Support\Facades\Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author']);
        $this->author = User::query()->findOrFail(7);
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return response()->json(['props' => $props]);
            }
        });
        Route::beamUxSite('test', withNav: false);
    }

    public function test_a_publish_outside_a_release_leaves_its_redirects_with_the_publish_origin(): void
    {
        $about = $this->livePage('about');
        $team = $this->livePage('team', $about);

        app(EntryPublication::class)->publishVersion($about->fresh(), $this->move($about, 'company'));

        $this->assertSame([
            ['/about', $about->id, 'publish', null],
            ['/about/team', $team->id, 'publish', null],
        ], $this->rows());
        $this->get('/about/team?x=1')->assertStatus(301)->assertRedirect('/company/team?x=1');
        $this->assertSame(['created', 'created'], DB::table('activity_log')->where('log_name', 'beam-redirect')->pluck('event')->all());
        $this->assertSame('publish', json_decode(DB::table('activity_log')->where('log_name', 'beam-redirect')->value('properties'), true)['origin']);
    }

    public function test_a_release_leaves_its_moves_and_its_takedowns_redirects_with_its_id_and_its_publisher(): void
    {
        $pricing = $this->livePage('pricing');
        $about = $this->livePage('about');
        $legacy = $this->livePage('legacy');
        $child = $this->livePage('child', $legacy);
        $this->bindLifecycle([$pricing, $about, $legacy, $child]);
        $service = app(ReleaseService::class);
        $release = $service->create($about, 'Relaunch', $this->author);
        $service->putMember($release, $about->id, $this->move($about, 'company'), $this->author);
        $service->putMember($release, $legacy->id, null, $this->author, '/pricing');

        $service->transition($release, 'publish', $this->author);

        $this->assertSame([
            ['/about', $about->id, 'release', (string) $release->id],
            ['/legacy', $pricing->id, 'takedown', (string) $release->id],
            ['/legacy/child', $pricing->id, 'takedown', (string) $release->id],
        ], $this->rows());
        $this->get('/legacy/child')->assertStatus(301)->assertRedirect('/pricing');
        $this->assertSame(['7'], DB::table('activity_log')->where('log_name', 'beam-redirect')->distinct()->pluck('causer_id')->map(fn ($id) => (string) $id)->all(),
            'each Redirect records who published the Release');
    }

    public function test_a_takedown_target_must_be_live_once_the_release_publishes(): void
    {
        $legacy = $this->livePage('legacy');
        $child = $this->livePage('child', $legacy);
        $fresh = $this->draftPage('fresh');
        $this->bindLifecycle([$legacy, $child]);
        $service = app(ReleaseService::class);

        $hidden = $service->create($legacy, 'Hidden target', $this->author);
        $service->putMember($hidden, $legacy->id, null, $this->author, $child->id);
        $blockers = app(ReleaseReadiness::class)->report($hidden)->blockers;
        $this->assertSame(['takedown'], array_map(fn ($item) => $item->code, $blockers), 'the bar lists the refusal (d4)');
        try {
            $service->transition($hidden, 'publish', $this->author);
            $this->fail('A target the Release hides is refused.');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString('live', implode(' ', $refused->errors()[array_key_first($refused->errors())]));
        }
        $service->transition($hidden, 'revise', $this->author);
        $service->dropMember($hidden, $legacy->id, $this->author);

        // A page this Release publishes for the first time is live once it publishes (EP-31 ruling 4).
        $launch = $service->create($fresh, 'Launch', $this->author);
        $service->putMember($launch, $fresh->id, $this->move($fresh, 'fresh'), $this->author);
        $service->putMember($launch, $legacy->id, null, $this->author, $fresh->id);
        $service->transition($launch, 'publish', $this->author);
        $this->get('/legacy/child')->assertStatus(301)->assertRedirect('/fresh');
    }

    public function test_an_exact_redirect_already_at_a_url_a_move_takes_away_now_leads_to_the_page(): void
    {
        $about = $this->livePage('about');
        $stale = (string) Str::uuid();
        DB::table('beam_ux_redirects')->insert(['id' => $stale, 'realm' => 'site', 'path' => '/about', 'match' => 'exact',
            'target_kind' => 'path', 'target_path' => '/elsewhere', 'permanent' => false, 'origin' => 'author', 'created_at' => now(), 'updated_at' => now()]);

        app(EntryPublication::class)->publishVersion($about->fresh(), $this->move($about, 'company'));

        $row = BeamUxRedirect::query()->findOrFail($stale);
        $this->assertSame(['page', $about->id, null, true, 'publish'], [$row->target_kind, $row->target_entry_id, $row->target_path, (bool) $row->permanent, $row->origin]);
        $this->assertSame(1, BeamUxRedirect::query()->count());
    }

    /** @return list<array{0: string, 1: ?string, 2: string, 3: ?string}> path, target page, origin, origin ref */
    private function rows(): array
    {
        return BeamUxRedirect::query()->orderBy('path')->get()
            ->map(fn (BeamUxRedirect $r) => [$r->path, $r->target_entry_id, $r->origin, $r->origin_ref === null ? null : (string) $r->origin_ref])->all();
    }

    /** Pages bound to the entry publish lifecycle, which a Take-down needs, each one published. */
    private function bindLifecycle(array $pages): void
    {
        app(\Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry::class)->bind('page', \Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle::DEFINITION);
        foreach ($pages as $page) {
            $page->forceFill(['workflow_marking' => BeamUxEntry::MARKING_PUBLISHED])->save();
        }
    }

    private function move(BeamUxEntry $page, string $segment): string
    {
        $settings = EntryPageSettingsData::from([...EntryPageSettingsData::fromEntry($page->fresh())->toArray(), 'segment' => $segment]);

        return (string) app(EntryPublication::class)->recordDraft($page->fresh(), [$this->node()], null, $settings)->headVersion;
    }

    private function livePage(string $segment, ?BeamUxEntry $parent = null): BeamUxEntry
    {
        $page = $this->draftPage($segment, $parent);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->node()])->headVersion);
        DB::table('beam_ux_redirects')->delete();
        DB::table('activity_log')->where('log_name', 'beam-redirect')->delete();

        return $page->fresh();
    }

    private function draftPage(string $segment, ?BeamUxEntry $parent = null): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $segment.'-'.Str::random(4), 'title' => ucfirst($segment), 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => ($parent ?? BeamUxEntry::rootFor())->id, 'segment' => $segment]);
    }

    private function node(): array
    {
        return ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []];
    }
}
