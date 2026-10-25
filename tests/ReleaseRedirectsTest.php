<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Redirect\ReleaseRedirects;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;

/**
 * ADR-0236 d2, d4, d7, d8 (EP-31 slice 0): the Redirects a Release would leave, read before anything changes. A move
 * leaves one at every URL a guest could open before and no page answers after, to the page that was there, its subtree
 * included; a swap, a new page at an old URL, a root-absolute segment and a hidden page leave none. A Take-down with a
 * target sends every URL it hides there; without one they are the uniform 404. A Record Page that moves leaves a
 * path-and-below Redirect between its static prefixes (EP-31 ruling 2). The plan also names a Redirect a page will
 * replace (d7) and a page that publishes under a page hidden after it (d8). Publish, the readiness report and the
 * Preview all read this one plan.
 */
class ReleaseRedirectsTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
        // A page marked `review` is hidden, as an unpublished page is on a host bound to the publish lifecycle.
        $this->app->bind(EntryPublishGate::class, fn () => new class implements EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->workflow_marking !== 'review';
            }
        });
    }

    public function test_a_move_leaves_a_redirect_at_each_old_url_of_the_page_and_its_subtree(): void
    {
        $about = $this->livePage('about');
        $team = $this->livePage('team', $about);
        $jobs = $this->livePage('jobs', $team);

        $plan = $this->plan([$about->id => $this->move($about, 'company')]);

        $this->assertSame([
            ['/about', 'exact', 'page', $about->id, 'moved'],
            ['/about/team', 'exact', 'page', $team->id, 'moved'],
            ['/about/team/jobs', 'exact', 'page', $jobs->id, 'moved'],
        ], $this->rows($plan));
    }

    public function test_a_page_only_readers_may_read_keeps_its_old_url_for_them(): void
    {
        // Review of 5eb6e6a2: "a URL a guest could open" (d2) means any visitor's, read rights aside. Read rights apply
        // when the Redirect answers (d10: only a reader who may read the target gets it, no-store), so a members-only
        // page that moves keeps its members' links, as EP-30's save check and prune already count it as answering.
        $members = $this->livePage('members', null, ['access' => ['auth']]);
        $this->assertSame(['auth'], $members->access, 'a page only signed-in readers may read');

        $this->assertSame([['/members', 'exact', 'page', $members->id, 'moved']], $this->rows($this->plan([$members->id => $this->move($members, 'team')])));
    }

    public function test_a_swap_a_new_page_at_an_old_url_a_root_absolute_segment_and_a_hidden_page_leave_none(): void
    {
        $x = $this->livePage('x');
        $y = $this->livePage('y');
        $this->assertSame([], $this->rows($this->plan([$x->id => $this->move($x, 'y'), $y->id => $this->move($y, 'x')])), 'a swap');

        $old = $this->livePage('old');
        $fresh = $this->draftPage('fresh');
        $this->assertSame([['/old', 'exact', 'page', $old->id, 'moved']], $this->rows($this->plan([$old->id => $this->move($old, 'new')])));
        $this->assertSame([], $this->rows($this->plan([$old->id => $this->move($old, 'new'), $fresh->id => $this->move($fresh, 'old')])),
            'a new page takes the old URL');

        $docs = $this->livePage('docs');
        $this->livePage('/status', $docs);
        $this->assertSame([['/docs', 'exact', 'page', $docs->id, 'moved']], $this->rows($this->plan([$docs->id => $this->move($docs, 'guides')])),
            'a root-absolute child keeps its URL');

        $hidden = $this->livePage('hidden');
        $this->livePage('inside', $hidden);
        $hidden->forceFill(['workflow_marking' => 'review'])->save();
        $this->assertSame([], $this->rows($this->plan([$hidden->id => $this->move($hidden, 'elsewhere')])),
            'no guest could open a hidden page or the pages under it');
    }

    public function test_a_takedown_sends_every_url_it_hides_to_its_target_or_leaves_them_to_the_404(): void
    {
        $pricing = $this->livePage('pricing');
        $legacy = $this->livePage('legacy');
        $child = $this->livePage('child', $legacy);

        $this->assertSame([
            ['/legacy', 'exact', 'page', $pricing->id, 'takedown'],
            ['/legacy/child', 'exact', 'page', $pricing->id, 'takedown'],
        ], $this->rows($this->plan([], [$legacy->id => '/pricing'])));
        $this->assertSame($child->id, collect($this->plan([], [$legacy->id => $pricing->id])['redirects'])->firstWhere('path', '/legacy/child')['entryId'],
            'each names the page whose URL it was');
        $this->assertSame([], $this->rows($this->plan([], [$legacy->id => null])), 'no target: the uniform 404');
    }

    public function test_a_record_page_that_moves_leaves_a_path_and_below_redirect_between_its_static_prefixes(): void
    {
        $blog = $this->livePage('blog');
        $post = $this->livePage('{slug}', $blog, ['record_page' => ['collection' => 'posts', 'field' => 'slug']]);

        $this->assertSame([
            ['/blog', 'exact', 'page', $blog->id, 'moved'],
            ['/blog', 'prefix', 'path', '/posts', 'moved'],
        ], $this->rows($this->plan([$blog->id => $this->move($blog, 'posts')])));
        $this->assertSame($post->id, collect($this->plan([$blog->id => $this->move($blog, 'posts')])['redirects'])->firstWhere('match', 'prefix')['entryId']);

        // A page that leaves a URL a Record Page's pattern covers still leaves its Redirect: whether a Record answers
        // there is the renderer's to find, and a Record that does always wins (d7).
        $hello = $this->livePage('hello', $blog);
        $this->assertContains(['/blog/hello', 'exact', 'page', $hello->id, 'moved'], $this->rows($this->plan([$hello->id => $this->move($hello, 'greeting')])));
    }

    public function test_it_names_a_redirect_a_page_will_replace_and_a_page_that_stays_hidden(): void
    {
        $plans = $this->livePage('plans');
        $existing = (string) Str::uuid();
        DB::table('beam_ux_redirects')->insert(['id' => $existing, 'realm' => 'site', 'path' => '/pricing', 'match' => 'exact',
            'target_kind' => 'path', 'target_path' => '/plans', 'permanent' => true, 'origin' => 'author', 'created_at' => now(), 'updated_at' => now()]);

        $plan = $this->plan([$plans->id => $this->move($plans, 'pricing')]);

        $this->assertSame([['realm' => 'site', 'path' => '/pricing', 'redirectId' => $existing, 'entryId' => $plans->id]], $plan['replaced'],
            'd7: Publish removes the Redirect at the URL it puts a page on');

        $section = $this->livePage('section');
        $section->forceFill(['workflow_marking' => 'review'])->save();
        $late = $this->draftPage('late', $section);
        $this->assertSame([$late->id], $this->plan([$late->id => $this->move($late, 'late')])['staysHidden'],
            'd8: a page published under a hidden page stays hidden');
    }

    /** @param array<string, string> $refs */
    private function plan(array $refs, array $takedowns = []): array
    {
        return app(ReleaseRedirects::class)->plan(new BeamUxEntry, $refs, $takedowns);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: string}> */
    private function rows(array $plan): array
    {
        return collect($plan['redirects'])
            ->map(fn (array $r) => [$r['path'], $r['match'], $r['targetKind'], $r['targetKind'] === 'page' ? $r['targetEntryId'] : $r['targetPath'], $r['reason']])
            ->sortBy(fn (array $r) => $r[0].' '.$r[1])->values()->all();
    }

    /** A saved version of the page whose settings name a new segment, as an author's draft would; its id is the ref. */
    private function move(BeamUxEntry $page, string $segment): string
    {
        $settings = EntryPageSettingsData::from([...EntryPageSettingsData::fromEntry($page->fresh())->toArray(), 'segment' => $segment]);

        return (string) app(EntryPublication::class)->recordDraft($page->fresh(), [$this->node()], null, $settings)->headVersion;
    }

    private function livePage(string $segment, ?BeamUxEntry $parent = null, array $extra = []): BeamUxEntry
    {
        $page = $this->draftPage($segment, $parent, $extra);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->node()])->headVersion);

        return $page->fresh();
    }

    private function draftPage(string $segment, ?BeamUxEntry $parent = null, array $extra = []): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => trim(str_replace(['{', '}'], '', $segment), '/').'-'.Str::random(4), 'type' => 'page', 'format' => 'tsx',
            'realm' => 'site', 'parent_id' => ($parent ?? BeamUxEntry::rootFor())->id, 'segment' => $segment] + $extra);
    }

    private function node(): array
    {
        return ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []];
    }
}
