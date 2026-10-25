<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Ux\Data\ReleaseData;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Release\ReleaseLifecycle;

/**
 * A Release reads by the same rule as the Releases list (ADR-0226 d7: anyone who can edit may assemble, so anyone who
 * can author reads them). The list declared `ux.author`, but showing one asked its model policy for `view` and there
 * was none: every author who could list a Release got 403 opening it (the EP-15 receipt, 2026-10-25), and the
 * operator Releases list drills into exactly that.
 */
class ReleaseReadTest extends TestCase
{
    private BeamUxRelease $release;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'add_preview_links_to_beam_ux_releases_table'] as $stub) {
            (require __DIR__."/../database/migrations/shared/{$stub}.php.stub")->up();
        }
        // A Release projects its members' pins from the entries table; this one has none, but the read still asks.
        \Illuminate\Support\Facades\Schema::create('beam_ux_entries', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('published_version')->nullable();
            $table->string('workflow_marking')->nullable();
            $table->softDeletes();
        });
        app(AttributedParticleDiscovery::class)->registerClass(ReleaseData::class);
        Particle::mount('beam-ux-releases', 'beam-ux-release')->only(['index', 'show']);
        Gate::define('ux.author', fn ($user = null) => $user?->getAuthIdentifier() === 'author');

        // The row ReleaseService::create() writes, without the entry tables its authorization reads.
        app(\Splicewire\Beam\Workflows\Definition\DefinitionStore::class)
            ->ensureSystemLineage(ReleaseLifecycle::DEFINITION, 'UX Release', ReleaseLifecycle::blueprint());
        $this->release = tap((new BeamUxRelease)->forceFill([
            'title' => 'Spring launch', 'realm' => 'site', 'created_by' => 'user:author', 'edited_by' => 'user:author',
            'status' => 'draft', 'origin' => 'built',
        ]))->save();
    }

    public function test_an_author_who_can_list_a_release_can_open_it(): void
    {
        $this->actingAs($this->user('author'));

        $this->getJson('/beam-ux-releases')->assertOk()->assertJsonPath('data.0.id', $this->release->id);
        $this->getJson("/beam-ux-releases/{$this->release->id}")->assertOk()
            ->assertJsonPath('data.id', $this->release->id)
            ->assertJsonPath('data.title', 'Spring launch');
    }

    public function test_a_release_shows_its_author_whether_preview_links_are_on_and_a_fresh_link_when_they_are(): void
    {
        // ADR-0232 d8 (EP-18): the Release panel offers the link to copy; it is minted on read, so it lasts 7 days from then.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        \Illuminate\Support\Facades\Route::beamUxSite('site/entry');
        app('router')->getRoutes()->refreshNameLookups();
        $this->actingAs($this->user('author'));

        $this->getJson("/beam-ux-releases/{$this->release->id}")->assertOk()
            ->assertJsonPath('data.previewLinksEnabled', false)->assertJsonPath('data.previewLink', null);

        app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->previewLinks($this->release, true, $this->user('author'));
        $link = $this->getJson("/beam-ux-releases/{$this->release->id}")->assertOk()
            ->assertJsonPath('data.previewLinksEnabled', true)->json('data.previewLink');

        $this->assertStringContainsString("/_beam/preview/release/{$this->release->id}?", (string) $link);
        $this->assertStringNotContainsString((string) $this->release->fresh()->getAttribute('preview_link_secret'), (string) $link, 'the secret never leaves the server');
    }

    public function test_an_author_reads_what_would_stop_a_release_publishing_and_a_reader_cannot(): void
    {
        // ADR-0232 d11 (EP-18): the Release panel and the Preview bar read the same report.
        app(AttributedParticleDiscovery::class)->registerClass(\Splicewire\Beam\Ux\Particle\ReleaseReadinessOp::class);
        Particle::ops('beam-ux-releases', 'beam-ux-release', 'readiness');
        // An operation's subject-free ability is asked as its entitlement, which a host registers for `ux.author`.
        Gate::define('entitlement:ux.author', fn ($user = null) => $user?->getAuthIdentifier() === 'author');

        $this->actingAs($this->user('author'));
        $this->getJson("/beam-ux-releases/{$this->release->id}/readiness")->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.blockers.0.code', 'empty')
            ->assertJsonPath('data.notes', []);

        $this->actingAs($this->user('reader'));
        $this->getJson("/beam-ux-releases/{$this->release->id}/readiness")->assertForbidden();
    }

    public function test_an_author_reads_the_pages_a_release_changes_and_a_reader_cannot(): void
    {
        // ADR-0232 d10 (EP-18): the Release panel lists its changed pages and their Reach, as the Preview bar does.
        app(AttributedParticleDiscovery::class)->registerClass(\Splicewire\Beam\Ux\Particle\ReleaseChangesOp::class);
        Particle::ops('beam-ux-releases', 'beam-ux-release', 'changes');
        Gate::define('entitlement:ux.author', fn ($user = null) => $user?->getAuthIdentifier() === 'author');

        $this->actingAs($this->user('author'));
        $this->getJson("/beam-ux-releases/{$this->release->id}/changes")->assertOk()
            ->assertJsonPath('data.pages', [])
            ->assertJsonPath('data.reach', []);

        $this->actingAs($this->user('reader'));
        $this->getJson("/beam-ux-releases/{$this->release->id}/changes")->assertForbidden();
    }

    public function test_a_release_with_a_saved_member_links_its_author_to_its_preview(): void
    {
        // ADR-0232 d15 (EP-18 slice 4): the Release panel's Preview is a plain link to the author route.
        \Illuminate\Support\Facades\Route::beamUxSite('site/entry');
        app('router')->getRoutes()->refreshNameLookups();
        $this->actingAs($this->user('author'));
        $this->getJson("/beam-ux-releases/{$this->release->id}")->assertOk()->assertJsonPath('data.previewHref', null);

        \Illuminate\Support\Facades\DB::table('beam_ux_release_members')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'release_id' => $this->release->id, 'entry_id' => (string) \Illuminate\Support\Str::uuid(), 'version_id' => (string) \Illuminate\Support\Str::uuid()]);

        $this->getJson("/beam-ux-releases/{$this->release->id}")->assertOk()
            ->assertJsonPath('data.previewHref', "/_beam/preview/open/{$this->release->id}");
    }

    public function test_someone_who_cannot_author_can_neither_list_nor_open_a_release(): void
    {
        $this->actingAs($this->user('reader'));

        $this->getJson('/beam-ux-releases')->assertForbidden();
        $this->getJson("/beam-ux-releases/{$this->release->id}")->assertForbidden();
    }

    private function user(string $id): User
    {
        $user = new User;
        $user->setKeyType('string');
        $user->forceFill(['id' => $id]);

        return $user;
    }
}
