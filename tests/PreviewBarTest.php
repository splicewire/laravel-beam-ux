<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\ReleasePreviewLinks;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * The Preview bar's server half (ADR-0232 d10, d11, d13; EP-18 slice 3): a Release's Preview carries its changed pages
 * and everything that would stop Publish, and the bar switches the same URL between the Preview and the live site. The
 * site's root renders inside a Preview too (an EP-17 gap: `/` had no Preview middleware).
 */
class PreviewBarTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create_beam_ux_preview_grants_table', 'create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table',
            'add_preview_links_to_beam_ux_releases_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return response()->json(['props' => $props]);
            }
        });
        Route::beamUxSite('test', claimRoot: true, withNav: false);
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_the_bar_carries_the_releases_changes_and_blockers_and_switches_one_url_to_the_live_site(): void
    {
        $pub = app(EntryPublication::class);
        $about = $this->livePage('about');
        $gone = $this->livePage('gone');
        $credential = $this->openLink($this->release([
            $about->id => $pub->recordDraft($about, [$this->node('section')])->headVersion,
            $gone->id => null,
        ]));

        $bar = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)->get('/about')->assertOk()->json('props.preview');

        $this->assertSame('preview', $bar['mode']);
        $this->assertSame('/_beam/preview/switch?to=live&path=%2Fabout', $bar['switchUrl']);
        $this->assertSame('/_beam/preview/switch?to=preview', $bar['previewUrl'], 'how a live page links back into the Preview');
        $this->assertSame([$about->id], array_column($bar['changes']['pages'], 'entryId'));
        $this->assertArrayHasKey('blockers', $bar['readiness']);

        // The Release takes /gone down: the Preview answers 404, and the live site still serves it, bar and all.
        $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)->get('/gone')->assertNotFound();
        $switched = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)->get('/_beam/preview/switch?to=live&path=%2Fgone');
        $switched->assertRedirect('/gone');
        $this->assertSame('live', $switched->getCookie(ResolvePreviewContext::MODE_COOKIE, false)?->getValue());
        $live = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)
            ->withUnencryptedCookie(ResolvePreviewContext::MODE_COOKIE, 'live')->get('/gone')->assertOk();
        $this->assertSame('live', $live->json('props.preview.mode'));
        $this->assertSame('/_beam/preview/switch?to=preview&path=%2Fgone', $live->json('props.preview.switchUrl'));
        $this->assertSame('no-store, private', $live->headers->get('Cache-Control'), 'a page with the bar is never cached');

        $back = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)
            ->withUnencryptedCookie(ResolvePreviewContext::MODE_COOKIE, 'live')->get('/_beam/preview/switch?to=preview&path=%2Fgone');
        $back->assertRedirect('/gone');
        $this->assertTrue($back->getCookie(ResolvePreviewContext::MODE_COOKIE, false)?->isCleared());
    }

    public function test_a_page_the_release_adds_has_no_live_page_to_switch_to(): void
    {
        $pub = app(EntryPublication::class);
        $page = BeamUxEntry::create(['slug' => 'brand-new', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'brand-new']);
        $credential = $this->openLink($this->release([$page->id => $pub->recordDraft($page, [$this->node('div')])->headVersion]));

        $bar = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)->get('/brand-new')->assertOk()->json('props.preview');

        $this->assertNull($bar['switchUrl']);
    }

    public function test_the_sites_root_renders_inside_a_preview(): void
    {
        // The home page is the one whose segment is `/`.
        $this->publish(BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site', 'segment' => '/']), [$this->node('div')]);
        $credential = $this->openLink($this->release([$this->livePage('about')->id => null]));

        $response = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)->get('/')->assertOk();

        $this->assertSame('preview', $response->json('props.preview.mode'));
        $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
    }

    public function test_a_host_page_reads_its_entry_and_the_bar_through_the_preview(): void
    {
        // A host's own page (a starter's home) addresses its entry through PageEntryRef and asks PreviewBar for the bar.
        $pub = app(EntryPublication::class);
        // A host route means a page in the configured namespace (PageEntryRef's tiebreak).
        $home = $this->livePage('home-page');
        $home->forceFill(['namespace' => config('beam.ux.namespace', 'starter')])->save();
        $draft = $pub->recordDraft($home, [$this->node('section')])->headVersion;
        $release = $this->release([$home->id => $draft]);
        $live = \Splicewire\Beam\Ux\Support\PageEntryRef::for('home-page');
        $grant = tap(new \Splicewire\Beam\Ux\Models\BeamUxPreviewGrant)->forceFill(['release_id' => $release->id])->setRelation('release', $release);
        $context = new \Splicewire\Beam\Ux\Preview\PreviewContext($grant);

        $previewed = \Splicewire\Beam\Ux\Support\PageEntryRef::for('home-page', $context);

        $artifacts = app(\Splicewire\Beam\Ux\Compile\EntryArtifactStore::class);
        $this->assertSame($artifacts->versionKey($draft), $previewed['artifact']['version'], 'the Release\'s version of the body');
        $this->assertSame($artifacts->version($home->fresh()), $live['artifact']['version'], 'outside a Preview, unchanged');

        $request = \Illuminate\Http\Request::create('/');
        $this->assertNull(app(\Splicewire\Beam\Ux\Preview\PreviewBar::class)->for($request), 'no Preview, no bar');
        $request->attributes->set('beam.ux.preview', $context);
        $bar = app(\Splicewire\Beam\Ux\Preview\PreviewBar::class)->for($request, $home->fresh());
        $this->assertSame(['Spring', 'preview', '/_beam/preview/switch?to=live&path=%2F'], [$bar['label'], $bar['mode'], $bar['switchUrl']],
            'outside the mount, the configured route names');
        $this->assertSame([$home->id], array_column($bar['changes']['pages'], 'entryId'));
        $this->assertSame([], $bar['recordChoices']);
    }

    public function test_a_host_page_the_preview_takes_down_is_gone_there_and_shown_live(): void
    {
        // Review of 0a56758d: a member Take-down is not admitted as a draft, and the ref fell back to the live body.
        $home = $this->livePage('home-page');
        $home->forceFill(['namespace' => config('beam.ux.namespace', 'starter')])->save();
        $release = $this->release([$home->id => null]);
        $grant = tap(new \Splicewire\Beam\Ux\Models\BeamUxPreviewGrant)->forceFill(['release_id' => $release->id])->setRelation('release', $release);
        $context = new \Splicewire\Beam\Ux\Preview\PreviewContext($grant);

        $this->assertNotNull(\Splicewire\Beam\Ux\Support\PageEntryRef::for('home-page')['artifact']);
        $this->assertFalse(\Splicewire\Beam\Ux\Support\PageEntryRef::hiddenByPreview('home-page'));

        $this->assertNull(\Splicewire\Beam\Ux\Support\PageEntryRef::for('home-page', $context)['artifact'], 'never the live body');
        $this->assertTrue(\Splicewire\Beam\Ux\Support\PageEntryRef::hiddenByPreview('home-page', $context), 'the host route answers 404, as the renderer does');
    }

    public function test_a_host_pages_head_reads_the_previews_page_settings(): void
    {
        // Review of 8e2cee5a: the starter's `/` composed its head from the live row inside a Preview.
        $pub = app(EntryPublication::class);
        $home = $this->livePage('home-page');
        $home->forceFill(['namespace' => config('beam.ux.namespace', 'starter'), 'title' => 'Live title', 'seo_description' => 'Live description'])->save();
        $draft = $pub->recordDraft($home->fresh(), [$this->node('section')], pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(
            title: 'Previewed title', parentId: $home->parent_id, segment: 'home-page', description: 'Previewed description'))->headVersion;
        $release = $this->release([$home->id => $draft]);
        $grant = tap(new \Splicewire\Beam\Ux\Models\BeamUxPreviewGrant)->forceFill(['release_id' => $release->id])->setRelation('release', $release);
        $request = \Illuminate\Http\Request::create('/');

        $live = implode("\n", \Splicewire\Beam\Ux\Head\PageHead::forPage('home-page', $request));
        $request->attributes->set('beam.ux.preview', new \Splicewire\Beam\Ux\Preview\PreviewContext($grant));
        $previewed = implode("\n", \Splicewire\Beam\Ux\Head\PageHead::forPage('home-page', $request));

        $this->assertStringContainsString('Live title', $live);
        $this->assertStringContainsString('Live description', $live);
        $this->assertStringContainsString('Previewed title', $previewed);
        $this->assertStringContainsString('Previewed description', $previewed);
        $this->assertStringNotContainsString('Live title', $previewed);
    }

    public function test_an_author_opens_a_releases_preview_on_its_first_changed_page(): void
    {
        // ADR-0232 d15 (EP-18 slice 4): the Release page's Preview. Signed in, no link needed; the same 8-hour Preview.
        $pub = app(EntryPublication::class);
        $about = $this->livePage('about');
        $release = $this->release([$about->id => $pub->recordDraft($about, [$this->node('section')])->headVersion]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => $user?->getAuthIdentifier() === 'author');

        $this->getJson("/_beam/preview/open/{$release->id}")->assertUnauthorized();
        $this->actingAs($this->user('reader'))->get("/_beam/preview/open/{$release->id}")->assertForbidden();
        $opened = $this->actingAs($this->user('author'))->get("/_beam/preview/open/{$release->id}");

        $opened->assertRedirect('/about');
        $this->assertSame('no-store, private', $opened->headers->get('Cache-Control'));
        $grant = app(\Splicewire\Beam\Ux\Preview\PreviewGrantResolver::class)
            ->resolve((string) $opened->getCookie(ResolvePreviewContext::COOKIE, false)?->getValue());
        $this->assertNotNull($grant);
        $this->assertSame([$release->id, 'author'], [$grant->release_id, $grant->created_by]);
        $this->assertEqualsWithDelta(now()->addHours(8)->getTimestamp(), $grant->expires_at->getTimestamp(), 60);
    }

    public function test_a_release_with_nothing_saved_to_render_has_no_preview_to_open(): void
    {
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => $user?->getAuthIdentifier() === 'author');
        $release = $this->release([$this->livePage('about')->id => null]);

        $this->actingAs($this->user('author'))->get("/_beam/preview/open/{$release->id}")->assertNotFound();
    }

    public function test_the_switch_needs_a_preview_and_returns_only_to_this_site(): void
    {
        $credential = $this->openLink($this->release([$this->livePage('about')->id => null]));

        $this->get('/_beam/preview/switch?to=live&path=%2Fabout')->assertNotFound();
        $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)->get('/_beam/preview/switch?to=live&path=%2F%2Fevil.example')->assertStatus(400);
        $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $credential)->get('/_beam/preview/switch?to=sideways&path=%2Fabout')->assertStatus(400);
    }

    /** @param array<string, ?string> $members entry id => version, null for a Take-down */
    private function release(array $members): BeamUxRelease
    {
        $release = tap((new BeamUxRelease)->forceFill([
            'title' => 'Spring', 'realm' => 'site', 'created_by' => 'user:author', 'edited_by' => 'user:author',
            'status' => 'draft', 'origin' => 'built', 'preview_links_enabled' => true, 'preview_link_secret' => ReleasePreviewLinks::newSecret(),
        ]))->save();
        foreach ($members as $entryId => $version) {
            $entry = BeamUxEntry::findOrFail($entryId);
            $entry->getConnection()->table('beam_ux_release_members')->insert([
                'id' => (string) Str::uuid(), 'release_id' => $release->id, 'entry_id' => $entryId, 'version_id' => $version,
                'observed_pin' => $entry->published_version, 'observed_marking' => $entry->workflow_marking,
            ]);
        }

        return $release;
    }

    /** Opens the Release's Preview Link as a guest; the routes are mounted bare, so the cookie reads as set. */
    private function openLink(BeamUxRelease $release): string
    {
        $response = $this->get((string) app(ReleasePreviewLinks::class)->url($release->fresh()))->assertRedirect();

        return (string) $response->getCookie(ResolvePreviewContext::COOKIE, false)?->getValue();
    }

    private function livePage(string $segment): BeamUxEntry
    {
        $page = BeamUxEntry::create(['slug' => $segment, 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => $segment]);

        return $this->publish($page, [$this->node('div')]);
    }

    private function publish(BeamUxEntry $entry, array $body): BeamUxEntry
    {
        $pub = app(EntryPublication::class);
        $pub->publishVersion($entry->fresh(), $pub->recordDraft($entry->fresh(), $body)->headVersion);

        return $entry->fresh();
    }

    private function user(string $id): \Illuminate\Foundation\Auth\User
    {
        $user = new \Illuminate\Foundation\Auth\User;
        $user->setKeyType('string');
        $user->forceFill(['id' => $id]);

        return $user;
    }

    private function node(string $name): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'dynamic' => false, 'props' => [], 'children' => []];
    }
}
