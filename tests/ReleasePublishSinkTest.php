<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Recording\GitRecordingSink;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Release\Sinks\PublishSink;
use Splicewire\Beam\Ux\Release\Sinks\PublishSinkRegistry;
use Splicewire\Beam\Ux\Release\Sinks\ReleaseProjection;

/**
 * config-sections 04 step 4 (ADR-0243): a published Release's records are sinks. The pin is the publication; a sink is
 * an after-commit projection that freezes inside the transaction and may fail without failing it. `git` is the local
 * recording (ADR-0226), unchanged.
 */
class ReleasePublishSinkTest extends AuthoredComponentNativeTestCase
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
        ProbeSink::$seen = [];
    }

    public function test_the_registry_is_in_the_index_conforms_and_seeds_git(): void
    {
        $sinks = app(PublishSinkRegistry::class);

        $this->assertTrue(app(RegistryIndex::class)->has('beam.ux.publish-sinks'));
        $this->assertSame($sinks, app(RegistryIndex::class)->resolve('beam.ux.publish-sinks'));
        $this->assertInstanceOf(Registry::class, $sinks);
        $this->assertSame(['git'], $sinks->names());
        $this->assertInstanceOf(GitRecordingSink::class, $sinks->resolve('git'));
    }

    public function test_every_sink_sees_the_projection_and_a_failing_sink_fails_nothing(): void
    {
        app(PublishSinkRegistry::class)->register(new FailingSink)->register(new ProbeSink);
        $page = $this->draftPage('about');
        $version = $this->saveDraft($page);

        $release = app(ReleaseService::class)->requestOne($page->fresh(), $version, $this->author);

        $this->assertSame('published', $release->status, 'a failing sink never fails the publication');
        $this->assertSame($version, $page->fresh()->published_version, 'the pin is the publication');
        $this->assertSame([['entry', [(string) $page->id], 0]], ProbeSink::$seen, 'the next sink still ran');
        $this->assertSame(0, DB::table('beam_ux_releases')->where('title', 'written by a failed sink')->count(),
            'what a failing sink wrote rolled back with its savepoint, and only that');
    }

    public function test_the_git_sink_records_entry_projections_alone(): void
    {
        $this->mock(ReleaseRecording::class)->shouldNotReceive('prepare');

        (new GitRecordingSink)->project(new BeamUxRelease, new ReleaseProjection('config', ['mail' => 'v2']), $this->author);
    }

    private function saveDraft(BeamUxEntry $page): string
    {
        return (string) app(EntryPublication::class)->recordDraft($page->fresh(), [['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]])->headVersion;
    }

    private function draftPage(string $segment): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $segment.'-'.Str::random(4), 'title' => ucfirst($segment), 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => $segment]);
    }
}

/** Writes inside its savepoint, then throws. */
class FailingSink implements PublishSink
{
    public function name(): string
    {
        return 'failing';
    }

    public function project(BeamUxRelease $release, ReleaseProjection $projection, Authenticatable $actor): void
    {
        $release->getConnection()->table('beam_ux_releases')->where('id', $release->id)->update(['title' => 'written by a failed sink']);

        throw new \RuntimeException('the sink is down');
    }
}

/** Records the kind, the published subjects and the withdrawn count it was handed. */
class ProbeSink implements PublishSink
{
    /** @var list<array{string, list<string>, int}> */
    public static array $seen = [];

    public function name(): string
    {
        return 'probe';
    }

    public function project(BeamUxRelease $release, ReleaseProjection $projection, Authenticatable $actor): void
    {
        self::$seen[] = [$projection->kind, array_values(array_map(fn (array $item) => (string) $item[0]->id, $projection->published)), count($projection->withdrawn)];
    }
}
