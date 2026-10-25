<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Ux\Data\RedirectData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * ADR-0236 d1, d5, d7, d14 (EP-30 slice 1): a Redirect is a row of its own. An author adds one by hand through the
 * Redirect resource: an exact path or a path and everything under it, to a page (named by its URL, held by identity),
 * a Record, a path or an address, permanent or temporary. The save refuses a path a page answers, a path the renderer
 * never receives, and a Redirect that would reach itself. Managing them takes `ux.author` plus the host's mapped publish
 * ability on a target page, as a direct Release checks. Every write records an activity row with its causer, and
 * Publish removes a Redirect at a URL it puts a page on.
 */
class RedirectResourceTest extends AuthoredComponentNativeTestCase
{
    private BeamUxEntry $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
        Gate::define('ux.author', fn ($user = null) => in_array($user?->getAuthIdentifier(), ['author', 'helper'], true));
        Gate::define('entitlement:ux.author', fn ($user = null) => in_array($user?->getAuthIdentifier(), ['author', 'helper'], true));
        app(AttributedParticleDiscovery::class)->registerClass(RedirectData::class);
        Particle::mount('beam-ux-redirects', 'beam-ux-redirect');
        $this->pricing = $this->livePage('pricing');
    }

    public function test_an_author_saves_a_redirect_to_a_page_named_by_its_url_and_held_by_identity(): void
    {
        $this->actingAs($this->user('author'));

        $saved = $this->postJson('/beam-ux-redirects', ['path' => '/plans/', 'match' => 'exact', 'targetKind' => 'page', 'target' => '/pricing'])
            ->assertCreated();

        $row = BeamUxRedirect::query()->findOrFail($saved->json('data.id'));
        $this->assertSame(['/plans', 'exact', 'page', $this->pricing->id, true, 'author', 'author'],
            [$row->path, $row->match, $row->target_kind, $row->target_entry_id, (bool) $row->permanent, $row->origin, $row->created_by],
            'the path normalised, the page held by its id, permanent by default, made by an author');
        $this->assertSame('/pricing', $saved->json('data.target'), 'the list shows the target page at its current URL');
        $activity = DB::table('activity_log')->where('log_name', 'beam-redirect')->get();
        $this->assertSame([['created', 'author']], $activity->map(fn ($row) => [$row->event, $row->causer_id])->all());

        $this->deleteJson("/beam-ux-redirects/{$row->id}")->assertSuccessful();
        $this->assertSame(0, BeamUxRedirect::query()->count());
        $this->assertSame(['created', 'deleted'], DB::table('activity_log')->where('log_name', 'beam-redirect')->orderBy('id')->pluck('event')->all());
    }

    public function test_the_save_refuses_a_path_a_page_answers_a_reserved_prefix_and_a_redirect_that_reaches_itself(): void
    {
        $this->actingAs($this->user('author'));
        $save = fn (array $input) => $this->postJson('/beam-ux-redirects', $input + ['match' => 'exact']);

        $save(['path' => '/pricing', 'targetKind' => 'path', 'target' => '/elsewhere'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $save(['path' => '/_beam/preview/x', 'targetKind' => 'path', 'target' => '/pricing'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $save(['path' => '/api/old', 'targetKind' => 'path', 'target' => '/pricing'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $save(['path' => '/gone', 'targetKind' => 'page', 'target' => '/nowhere'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $save(['path' => '/away', 'targetKind' => 'address', 'target' => 'not an address'])->assertUnprocessable()->assertJsonValidationErrors('target');

        $save(['path' => '/a', 'targetKind' => 'path', 'target' => '/b'])->assertCreated();
        $save(['path' => '/b', 'targetKind' => 'path', 'target' => '/a'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $save(['path' => '/self', 'targetKind' => 'path', 'target' => '/self'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->assertSame(['/a'], BeamUxRedirect::query()->pluck('path')->all());
    }

    public function test_a_page_named_by_id_must_be_one_the_redirects_site_reaches(): void
    {
        // Review of 86dd1a5b: an id from another realm passed, storing a URL this site doesn't serve (or serves as a
        // different page). An id is held only when the page's current URL resolves to it in the Redirect's realm.
        $docs = BeamUxEntry::create(['slug' => 'handbook', 'type' => 'page', 'format' => 'tsx', 'realm' => 'docs', 'realms' => ['docs'],
            'parent_id' => BeamUxEntry::rootFor('docs')->id, 'segment' => 'pricing']);
        $this->actingAs($this->user('author'));

        $this->postJson('/beam-ux-redirects', ['path' => '/handbook', 'match' => 'exact', 'targetKind' => 'page', 'target' => $docs->id])
            ->assertUnprocessable()->assertJsonValidationErrors('target');
        $saved = $this->postJson('/beam-ux-redirects', ['path' => '/prices', 'match' => 'exact', 'targetKind' => 'page', 'target' => $this->pricing->id])
            ->assertCreated();
        $this->assertSame($this->pricing->id, BeamUxRedirect::query()->findOrFail($saved->json('data.id'))->target_entry_id);
    }

    public function test_managing_redirects_takes_ux_author_and_the_mapped_publish_ability_on_a_target_page(): void
    {
        $this->actingAs($this->user('reader'));
        $this->postJson('/beam-ux-redirects', ['path' => '/plans', 'match' => 'exact', 'targetKind' => 'page', 'target' => '/pricing'])->assertForbidden();

        config(['beam.ux.releases.publish_ability' => 'publish']);
        Gate::define('publish', fn ($user, $entry) => $user->getAuthIdentifier() === 'author');
        $this->actingAs($this->user('helper'));
        $this->postJson('/beam-ux-redirects', ['path' => '/plans', 'match' => 'exact', 'targetKind' => 'page', 'target' => '/pricing'])->assertForbidden();
        $this->postJson('/beam-ux-redirects', ['path' => '/old-home', 'match' => 'prefix', 'targetKind' => 'path', 'target' => '/pricing'])->assertCreated();

        $this->actingAs($this->user('author'));
        $this->postJson('/beam-ux-redirects', ['path' => '/plans', 'match' => 'exact', 'targetKind' => 'page', 'target' => '/pricing', 'permanent' => false])->assertCreated();
        $this->assertFalse((bool) BeamUxRedirect::query()->where('path', '/plans')->value('permanent'), 'temporary by choice');
    }

    public function test_publishing_a_page_at_a_redirects_path_removes_the_redirect_and_records_it(): void
    {
        $this->actingAs($this->user('author'));
        $this->postJson('/beam-ux-redirects', ['path' => '/launch', 'match' => 'exact', 'targetKind' => 'page', 'target' => '/pricing'])->assertCreated();
        $this->postJson('/beam-ux-redirects', ['path' => '/launch', 'match' => 'prefix', 'targetKind' => 'path', 'target' => '/pricing'])->assertCreated();

        $this->livePage('launch');

        $this->assertSame(['prefix'], BeamUxRedirect::query()->where('path', '/launch')->pluck('match')->all(),
            'a page always wins at its own URL (d7); the path-and-below one still answers the URLs under it');
        $pruned = DB::table('activity_log')->where('log_name', 'beam-redirect')->where('event', 'pruned')->get();
        $this->assertCount(1, $pruned);
        $this->assertSame('/launch', json_decode($pruned[0]->properties, true)['path']);
    }

    private function livePage(string $segment): BeamUxEntry
    {
        $page = BeamUxEntry::create(['slug' => $segment, 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => $segment]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->node('div')])->headVersion);

        return $page->fresh();
    }

    private function node(string $name): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []];
    }

    private function user(string $id): User
    {
        $user = new User;
        $user->setKeyType('string');
        $user->forceFill(['id' => $id]);

        return $user;
    }
}
