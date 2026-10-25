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
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table'] as $stub) {
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
