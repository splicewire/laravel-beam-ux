<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Access\EntryRequirement;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\ReleasePreviewLinks;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Moved from the beam starter with the home route (frame-foundation 52).
 * ADR-0232 d15 (EP-18, ruling 8): `/` is this starter's own route, not the packaged renderer's, so a Preview there
 * showed the LIVE home with no bar. It now renders the Preview's version of the home body under the bar, and a home
 * the Preview takes down answers 404. Outside a Preview, `/` is unchanged (RoutePagePropsTest pins its props).
 */
class HomePreviewTest extends AuthoredComponentNativeTestCase
{
    private BeamUxEntry $home;

    private string $published;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create_beam_ux_preview_grants_table', 'create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'add_preview_links_to_beam_ux_releases_table'] as $name) {
            (require dirname(__DIR__)."/database/migrations/shared/{$name}.php.stub")->up();
        }
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        // Exercise the home contract without a JS toolchain or a served host.
        $this->app->singleton(\Splicewire\Beam\Ux\Compile\EntryBodyCompiler::class, fn () => new HomeTestCompiler);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Compile\CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return response()->json(['page' => $page, 'props' => $props]);
            }
        });
        \Illuminate\Support\Facades\Route::beamUxHome('site/home');
        \Illuminate\Support\Facades\Route::beamUxSite('site/entry');
        app('router')->getRoutes()->refreshNameLookups();
        // Isolate the UX mirror disk so publishing the fixture 'home' page cannot write the compiled
        // tsx into the repo's resources/beam-ux/starter/page/ (js:format then flags it). Mirror the
        // isolation ticket 01 adopts; EntryBodyTransportTest already fakes this disk.
        Storage::fake(config('beam.ux.storage.mirror_disk', 'beam-ux'));
        $this->home = BeamUxEntry::create(['slug' => 'home', 'type' => UxType::Page, 'format' => 'tsx', 'realm' => 'site',
            'namespace' => config('beam.ux.namespace', 'starter')]);
        $pub = app(EntryPublication::class);
        $this->published = $pub->recordDraft($this->home, [$this->node('Published home')])->headVersion;
        $pub->publishVersion($this->home->fresh(), $this->published);
    }

    public function test_the_front_door_renders_the_previews_home_under_the_bar(): void
    {
        // A requirement is not itself a denial: this one explicitly admits guests. Preview
        // metadata must use the same preview chain and guest-read decision as the page render.
        $this->home->forceFill(['requirements' => ['preview-guest-readable']])->save();
        app()->instance('beam.ux.requirement.preview-guest-readable', new class implements EntryRequirement
        {
            public function allows(?Authenticatable $actor, BeamUxEntry $entry): bool
            {
                return $actor === null;
            }
        });

        $draft = app(EntryPublication::class)->recordDraft($this->home->fresh(), [$this->node('Previewed home')],
            pageSettings: new EntryPageSettingsData(title: 'Previewed front door', description: 'What the Release says.'))->headVersion;
        $cookie = $this->openLink($this->release('Spring', $draft));
        $artifacts = app(EntryArtifactStore::class);

        // Outside a Preview first: a cookie set with withCookie() rides every later request in the test.
        $live = $this->withHeaders($this->headers())->get('/');
        $live->assertOk();
        $this->assertSame($artifacts->version($this->home->fresh()), $live->json('props.entry.artifact.version'), 'outside a Preview, the live body');
        $this->assertArrayNotHasKey('preview', $live->json('props'));
        $this->assertStringNotContainsString('Previewed front door', implode('', $live->json('props.head')));

        $previewed = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $cookie)->withHeaders($this->headers())->get('/');

        $previewed->assertOk();
        $this->assertSame($artifacts->versionKey($draft), $previewed->json('props.entry.artifact.version'), "the Release's body");
        $this->assertSame('Spring', $previewed->json('props.preview.label'));
        $this->assertSame('preview', $previewed->json('props.preview.mode'));
        $this->assertSame([$this->home->id], array_column($previewed->json('props.preview.changes.pages'), 'entryId'));
        $this->assertSame('no-store, private', $previewed->headers->get('Cache-Control'));
        // The head reads the Release's page settings too, as the packaged renderer's does.
        $this->assertStringContainsString('Previewed front door', $previewed->json('props.head.0'));
        $this->assertStringContainsString('What the Release says.', implode('', $previewed->json('props.head')));
        $this->assertStringNotContainsString('noindex', implode('', $previewed->json('props.head')),
            'a guest-readable requirement is not a reason to suppress the preview head');
    }

    public function test_the_preview_head_is_composed_from_the_page_at_the_end_of_its_chain(): void
    {
        // A home under a parent: the chain is [parent, home], and the head is the home's, never its parent's.
        $parent = BeamUxEntry::create(['slug' => 'front', 'type' => UxType::Page, 'format' => 'tsx', 'realm' => 'site',
            'title' => 'The parent page', 'namespace' => config('beam.ux.namespace', 'starter')]);
        // Published, as the home is: an unpublished parent would hide the chain and test the refusal instead.
        $pub = app(EntryPublication::class);
        $pub->publishVersion($parent->fresh(), $pub->recordDraft($parent, [$this->node('The parent page')])->headVersion);
        $this->home->forceFill(['parent_id' => $parent->getKey()])->save();

        $draft = app(EntryPublication::class)->recordDraft($this->home->fresh(), [$this->node('Previewed home')],
            pageSettings: new EntryPageSettingsData(title: 'The previewed home'))->headVersion;
        $cookie = $this->openLink($this->release('Nested', $draft));

        $previewed = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $cookie)->withHeaders($this->headers())->get('/');

        $previewed->assertOk();
        $this->assertStringContainsString('The previewed home', $previewed->json('props.head.0'));
        $this->assertStringNotContainsString('The parent page', $previewed->json('props.head.0'));
    }

    public function test_a_preview_chain_the_guest_may_not_read_composes_no_head(): void
    {
        // The gate refuses the chain (a requirement that denies guests), so no metadata about it is composed.
        $this->home->forceFill(['requirements' => ['preview-guest-denied']])->save();
        app()->instance('beam.ux.requirement.preview-guest-denied', new class implements EntryRequirement
        {
            public function allows(?Authenticatable $actor, BeamUxEntry $entry): bool
            {
                return false;
            }
        });

        $draft = app(EntryPublication::class)->recordDraft($this->home->fresh(), [$this->node('Previewed home')],
            pageSettings: new EntryPageSettingsData(title: 'A refused front door'))->headVersion;
        $cookie = $this->openLink($this->release('Refused', $draft));

        $previewed = $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $cookie)->withHeaders($this->headers())->get('/');

        $this->assertSame([], $previewed->json('props.head'));
    }

    public function test_a_home_the_preview_takes_down_answers_not_found_there_and_stays_live(): void
    {
        $cookie = $this->openLink($this->release('Take it down', null));

        $this->get('/')->assertOk();
        $this->withUnencryptedCookie(ResolvePreviewContext::COOKIE, $cookie)->get('/')->assertNotFound();
    }

    private function release(string $title, ?string $version): BeamUxRelease
    {
        $release = tap((new BeamUxRelease)->forceFill([
            'title' => $title, 'realm' => 'site', 'created_by' => 'user:author', 'edited_by' => 'user:author',
            'status' => 'draft', 'origin' => 'built', 'preview_links_enabled' => true, 'preview_link_secret' => ReleasePreviewLinks::newSecret(),
        ]))->save();
        $home = $this->home->fresh();
        DB::table('beam_ux_release_members')->insert([
            'id' => (string) Str::uuid(), 'release_id' => $release->id, 'entry_id' => $home->id, 'version_id' => $version,
            'observed_pin' => $home->published_version, 'observed_marking' => $home->workflow_marking,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $release;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Accept' => 'application/json'];
    }

    /** Opens the Release's Preview Link as a guest and returns the Preview cookie's value. */
    private function openLink(BeamUxRelease $release): string
    {
        $response = $this->get((string) app(ReleasePreviewLinks::class)->url($release->fresh()))->assertRedirect();

        return (string) $response->getCookie(ResolvePreviewContext::COOKIE, false)?->getValue();
    }

    private function node(string $text): array
    {
        return ['kind' => 'block', 'name' => 'h1', 'isComponent' => false, 'dynamic' => false, 'props' => [],
            'children' => [['kind' => 'text', 'value' => $text]]];
    }
}

/** Compilation is incidental to the route's version selection; keep this proof slot-light. */
class HomeTestCompiler implements \Splicewire\Beam\Ux\Compile\EntryBodyCompiler
{
    public function handles(BeamUxEntry $entry): bool
    {
        return true;
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        return 'export default () => '.json_encode($source);
    }
}
