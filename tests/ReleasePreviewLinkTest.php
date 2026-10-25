<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Preview\ReleasePreviewLinks;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0232 d8 (EP-18): a Release can turn on Preview Links. Anyone who can edit it makes them; one lasts 7 days by
 * default; the Release holds the links' secret, so rotating it ends every link at once, as turning links off or
 * publishing does. A link is stateless: it names one Release and an expiry, signed with that secret, and opening it
 * starts the same 8-hour Preview cookie a version preview does. It previews that Release and grants nothing else.
 */
class ReleasePreviewLinkTest extends TestCase
{
    private BeamUxRelease $release;

    private BeamUxRelease $other;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create_beam_ux_preview_grants_table', 'create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table',
            'add_preview_links_to_beam_ux_releases_table'] as $stub) {
            (require __DIR__."/../database/migrations/shared/{$stub}.php.stub")->up();
        }
        // The columns the containment walk reads when a link lands; these entries have no place in the tree, so a link
        // lands on the site's root.
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable();
            $table->string('slug')->nullable();
            $table->string('title')->nullable();
            $table->string('type')->default('page');
            $table->string('format')->default('tsx');
            $table->string('namespace')->nullable();
            $table->string('realm')->default('site');
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->string('segment')->nullable();
            $table->json('traverse')->nullable();
            $table->json('access')->nullable();
            $table->json('requirements')->nullable();
            $table->string('published_version')->nullable();
            $table->string('workflow_marking')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        // The links' signing key is derived from the install's key, as on every host.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Gate::define('ux.author', fn ($user = null) => $user?->getAuthIdentifier() === 'author');
        Route::beamUxSite('site/entry');
        // The macro names its routes after adding them; a host's boot refreshes the lookup once its route files load.
        app('router')->getRoutes()->refreshNameLookups();

        $this->release = $this->releaseWith('entry-a', 'version-a');
        $this->other = $this->releaseWith('entry-b', 'version-b');
    }

    public function test_a_release_link_lasts_seven_days_and_previews_only_that_release(): void
    {
        app(ReleaseService::class)->previewLinks($this->release, true, $this->user('author'));
        $url = app(ReleasePreviewLinks::class)->url($this->release->fresh());

        $this->assertNotNull($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), (int) $query['expires'], 60, 'seven days by default');

        $credential = $this->open($url);
        $context = $this->contextFor($credential);

        $this->assertNotNull($context);
        $this->assertSame($this->release->id, $context->grant->release_id);
        $this->assertTrue($context->contains('entry-a'));
        $this->assertSame('version-a', $context->versionFor('entry-a'));
        $this->assertFalse($context->contains('entry-b'), 'a link previews its own Release only');
        $this->assertSame(0, DB::table('beam_ux_preview_grants')->count(), 'a link is stateless: no grant row');
        $this->assertNull(auth()->user(), 'a link signs nobody in');
    }

    public function test_an_expired_link_a_rotated_secret_and_links_turned_off_are_refused(): void
    {
        $service = app(ReleaseService::class);
        $links = app(ReleasePreviewLinks::class);
        $service->previewLinks($this->release, true, $this->user('author'));

        $expiring = $links->url($this->release->fresh());
        $this->travel(7)->days();
        $this->travel(2)->minutes();
        $this->get((string) $expiring)->assertNotFound();
        $this->travelBack();

        // Rotating the secret ends every link, and every Preview a link already opened, on its next request.
        $opened = $this->open((string) $links->url($this->release->fresh()));
        $rotated = $links->url($this->release->fresh());
        $service->rotatePreviewLinks($this->release, $this->user('author'));
        $this->get((string) $rotated)->assertNotFound();
        $this->assertNull($this->contextFor($opened), 'an open Preview ends with the secret');

        $fresh = $links->url($this->release->fresh());
        $this->assertNotNull($this->contextFor($this->open((string) $fresh)));
        $service->previewLinks($this->release, false, $this->user('author'));
        $this->get((string) $fresh)->assertNotFound();
        $this->assertNull($links->url($this->release->fresh()), 'no link while links are off');
    }

    public function test_publishing_the_release_ends_its_links(): void
    {
        app(ReleaseService::class)->previewLinks($this->release, true, $this->user('author'));
        $url = (string) app(ReleasePreviewLinks::class)->url($this->release->fresh());
        $credential = $this->open($url);

        DB::table('beam_ux_releases')->where('id', $this->release->id)->update(['status' => 'published']);

        $this->get($url)->assertNotFound();
        $this->assertNull($this->contextFor($credential));
        $this->assertNull(app(ReleasePreviewLinks::class)->url($this->release->fresh()));
    }

    public function test_a_forged_signature_or_a_signature_for_another_release_is_refused(): void
    {
        $service = app(ReleaseService::class);
        $service->previewLinks($this->release, true, $this->user('author'));
        $service->previewLinks($this->other, true, $this->user('author'));
        $url = (string) app(ReleasePreviewLinks::class)->url($this->release->fresh());

        $this->get(preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $url))->assertNotFound();
        $this->get(str_replace($this->release->id, $this->other->id, $url))->assertNotFound();
        $this->assertNull($this->contextFor('link:'.$this->other->id.'.'.now()->addDay()->getTimestamp().'.'.str_repeat('a', 64)));
    }

    public function test_only_someone_who_can_edit_the_release_turns_links_on_or_rotates_them(): void
    {
        $service = app(ReleaseService::class);
        DB::table('beam_ux_releases')->where('id', $this->release->id)->update(['status' => 'approved', 'reviewed_by' => 'user:reviewer']);

        try {
            $service->previewLinks($this->release, true, $this->user('reader'));
            $this->fail('a reader turned links on');
        } catch (AuthorizationException) {
        }
        $service->previewLinks($this->release, true, $this->user('author'));
        $service->rotatePreviewLinks($this->release, $this->user('author'));

        $release = $this->release->fresh();
        $this->assertTrue((bool) $release->preview_links_enabled);
        $this->assertSame(['approved', 'user:reviewer'], [$release->status, $release->reviewed_by], 'links change no member, so the review stands');
    }

    /** Open a link as a guest and return the Preview credential its cookie carries. */
    private function open(string $url): string
    {
        $response = $this->get($url);
        $response->assertRedirect();
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        // Mounted bare here: a host's web group encrypts the cookie; this one reads as set.
        $cookie = $response->getCookie(ResolvePreviewContext::COOKIE, false);
        $this->assertNotNull($cookie);

        return (string) $cookie->getValue();
    }

    private function contextFor(string $credential): ?PreviewContext
    {
        $request = Request::create('/previewed');
        $request->cookies->set(ResolvePreviewContext::COOKIE, $credential);
        app(ResolvePreviewContext::class)->handle($request, fn () => new Response('page'));

        return $request->attributes->get('beam.ux.preview');
    }

    private function releaseWith(string $entry, string $version): BeamUxRelease
    {
        DB::table('beam_ux_entries')->insert(['id' => $entry, 'slug' => $entry, 'created_at' => now(), 'updated_at' => now()]);
        $release = tap((new BeamUxRelease)->forceFill([
            'title' => "Release {$entry}", 'realm' => 'site', 'created_by' => 'user:author', 'edited_by' => 'user:author',
            'status' => 'draft', 'origin' => 'built',
        ]))->save();
        DB::table('beam_ux_release_members')->insert([
            'id' => (string) Str::uuid(), 'release_id' => $release->id, 'entry_id' => $entry, 'version_id' => $version,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $release;
    }

    private function user(string $id): User
    {
        $user = new User;
        $user->setKeyType('string');
        $user->forceFill(['id' => $id]);

        return $user;
    }
}
