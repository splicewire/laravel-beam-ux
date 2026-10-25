<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * ADR-0236 d7, d9–d11 (EP-30 slice 2): the renderer looks a Redirect up only on a miss, so a page always wins. A
 * Redirect answers in one response, 301 or 302, with the query string kept; a page target at its current URL; a chain
 * followed within a bounded number of steps; a loop or dead end the uniform 404. It answers only a reader who may read
 * its target, and an answer that depends on the reader is `no-store, private`; otherwise it is cached briefly, a 301
 * included, so a browser doesn't keep it for good. An Inertia visit to another site gets Inertia's external location.
 */
class RedirectRendererTest extends AuthoredComponentNativeTestCase
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
        Route::beamUxSite('test', withNav: false);
        $this->pricing = $this->livePage('pricing');
    }

    public function test_a_redirect_reaches_its_page_wherever_it_is_now_and_keeps_the_query(): void
    {
        $this->redirect('/plans', 'page', ['target_entry_id' => $this->pricing->id]);

        $first = $this->get('/plans?ref=mail&utm=x');
        $first->assertStatus(301)->assertRedirect('/pricing?ref=mail&utm=x');
        $this->assertSame('max-age=60, public', $first->headers->get('Cache-Control'), 'a 301 carries an explicit max-age');

        $this->pricing->forceFill(['segment' => 'prices'])->save();
        $this->get('/plans')->assertStatus(301)->assertRedirect('/prices');
    }

    public function test_a_path_and_below_redirect_carries_the_rest_and_a_temporary_one_answers_302(): void
    {
        $this->redirect('/old-docs', 'path', ['target_path' => '/docs', 'match' => 'prefix', 'permanent' => false]);

        $this->get('/old-docs/guides/start')->assertStatus(302)->assertRedirect('/docs/guides/start');
        $this->get('/old-docs')->assertStatus(302)->assertRedirect('/docs');
    }

    public function test_an_address_on_another_site_and_its_inertia_visit(): void
    {
        $this->redirect('/out', 'address', ['target_url' => 'https://example.com/landing']);

        $this->get('/out?a=1')->assertStatus(301)->assertRedirect('https://example.com/landing?a=1');
        $visit = $this->withHeader('X-Inertia', 'true')->get('/out');
        $visit->assertStatus(409);
        $this->assertSame('https://example.com/landing', $visit->headers->get('X-Inertia-Location'));
        // Review of f2b5b70: the visit's answer is the visit's alone, so no cache keeps it for the URL.
        $this->assertSame('no-store, private', $visit->headers->get('Cache-Control'));
        $this->assertContains('X-Inertia', $visit->headers->all('vary'));
    }

    public function test_an_address_keeps_its_own_query_and_fragment_after_the_carried_path(): void
    {
        // Review of f2b5b70: the rest of the path was appended after the address's own query (…?fixed=1/x).
        $this->redirect('/old-shop', 'address', ['target_url' => 'https://example.com/shop?ref=old#top', 'match' => 'prefix']);

        $this->withoutHeader('X-Inertia')->get('/old-shop/hats?size=m')->assertStatus(301)
            ->assertRedirect('https://example.com/shop/hats?ref=old&size=m#top');
    }

    public function test_a_chain_is_followed_in_one_response_and_a_loop_or_dead_end_is_the_uniform_404(): void
    {
        $this->redirect('/one', 'path', ['target_path' => '/two']);
        $this->redirect('/two', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->get('/one')->assertStatus(301)->assertRedirect('/pricing');

        $this->redirect('/loop-a', 'path', ['target_path' => '/loop-b']);
        $this->redirect('/loop-b', 'path', ['target_path' => '/loop-a']);
        $this->get('/loop-a')->assertNotFound();

        // A path no page answers may still be a host's own route (a sign-in page): the guest goes there.
        $this->redirect('/sign-in-old', 'path', ['target_path' => '/login']);
        $this->get('/sign-in-old')->assertStatus(301)->assertRedirect('/login');

        // A page that is gone is a dead end.
        $this->redirect('/to-gone', 'page', ['target_entry_id' => (string) Str::uuid()]);
        $this->get('/to-gone')->assertNotFound();

        for ($i = 0; $i < 6; $i++) {
            $this->redirect("/hop-{$i}", 'path', ['target_path' => '/hop-'.($i + 1)]);
        }
        $this->redirect('/hop-6', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->get('/hop-0')->assertNotFound();
        $this->get('/hop-2')->assertStatus(301)->assertRedirect('/pricing');
    }

    public function test_a_hidden_target_is_looked_up_in_turn(): void
    {
        $hidden = $this->livePage('retired');
        $hidden->forceFill(['workflow_marking' => 'review'])->save();
        $this->app->bind(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class, fn () => new class implements \Splicewire\Beam\Ux\Sitemap\EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->workflow_marking === null || $entry->workflow_marking === BeamUxEntry::MARKING_PUBLISHED;
            }
        });
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Http\PublicEntryGate::class);
        $this->redirect('/old-retired', 'page', ['target_entry_id' => $hidden->id]);
        $this->get('/old-retired')->assertNotFound();
        // Review of 8ae0219b: a path naming a hidden page is the same dead end, not a host route to send the guest to.
        $this->redirect('/old-retired-path', 'path', ['target_path' => '/retired']);
        $this->get('/old-retired-path')->assertNotFound();

        $this->redirect('/retired', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->get('/old-retired')->assertStatus(301)->assertRedirect('/pricing');
        $this->get('/old-retired-path')->assertStatus(301)->assertRedirect('/pricing');
    }

    public function test_it_answers_only_a_reader_who_may_read_the_target(): void
    {
        $members = $this->livePage('members');
        $members->forceFill(['access' => ['auth']])->save();
        $this->redirect('/team', 'page', ['target_entry_id' => $members->id]);

        $this->redirect('/team-path', 'path', ['target_path' => '/members']);

        $this->get('/team')->assertNotFound();
        $this->get('/team-path')->assertNotFound();

        $reader = (new User)->forceFill(['id' => 1]);
        $answer = $this->actingAs($reader)->get('/team');
        $answer->assertStatus(301)->assertRedirect('/members');
        $this->assertSame('no-store, private', $answer->headers->get('Cache-Control'));
        $this->assertSame('no-store, private', $this->actingAs($reader)->get('/team-path')->assertStatus(301)->headers->get('Cache-Control'));
    }

    public function test_a_page_always_wins(): void
    {
        $this->redirect('/pricing', 'path', ['target_path' => '/elsewhere']);

        $this->get('/pricing')->assertOk();
    }

    private function redirect(string $path, string $kind, array $columns): BeamUxRedirect
    {
        return tap((new BeamUxRedirect)->forceFill($columns + [
            'id' => (string) Str::uuid(), 'realm' => 'site', 'path' => $path, 'match' => 'exact', 'target_kind' => $kind,
            'permanent' => true, 'origin' => 'author',
        ]))->save();
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
