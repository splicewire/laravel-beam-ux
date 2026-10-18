<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;
use Splicewire\Beam\Ux\Particle\EntryDraftSaveOp;
use Splicewire\Beam\Ux\Particle\EntryPublishOp;
use Splicewire\Beam\Ux\Particle\EntryVersionRestoreOp;
use Splicewire\Beam\Ux\Particle\EntryVersionsShowOp;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * **Draft, publish, and restore as two pins into one version history** (G2-BEAM-DRAFT-PUBLISH).
 *
 * The obligation this file exists for is the one a reader can see: a draft must be recorded and must
 * change NOTHING a guest is served, and a publish must move what a guest is served through the real
 * compile path. Both of those are statements about the ARTIFACT ADDRESS, not about a column, so every
 * case below reads the artifact the public renderer would address
 * ({@see EntryArtifactStore::path()} / {@see EntryArtifactStore::read()}) rather than only asserting
 * that a pin moved. A pin that moved while the reader kept getting the old module would pass a
 * column-only assertion exactly as well as a correct implementation.
 */
class EntryPublicationTest extends TestCase
{
    /** A JsonDoc body with one heading — the canvas shape a tsx entry stores. */
    private const BODY_ONE = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'One']],
    ]];

    private const BODY_TWO = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'Two']],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);

        // A compiler with no toolchain: these cases are about WHICH body reaches the artifact and at
        // WHICH address, and a real Node run would make every one of them depend on npm.
        $this->app->singleton(EntryBodyCompiler::class, fn () => new PublicationFakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    public function test_release_batch_publishes_fixed_versions_and_validates_url_swaps_together(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $one->forceFill(['segment' => 'one'])->save();
        $two->forceFill(['segment' => 'two'])->save();
        $pub = app(EntryPublication::class);
        $a = $pub->recordDraft($one, self::BODY_TWO, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'A', segment: 'two'));
        $b = $pub->recordDraft($two, self::BODY_ONE, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'B', segment: 'one'));
        $newer = $pub->recordDraft($one->fresh(), self::BODY_ONE);
        $pub->publishVersions($one, [$one->id => $a->headVersion, $two->id => $b->headVersion]);
        $this->assertSame($a->headVersion, $one->fresh()->published_version);
        $this->assertSame($b->headVersion, $two->fresh()->published_version);
        $this->assertSame($newer->headVersion, $pub->state($one->fresh())->headVersion);
        $this->assertSame('two', $one->fresh()->segment);
        $this->assertSame('one', $two->fresh()->segment);
    }

    public function test_release_requires_an_independent_review_and_publishes_its_fixed_member(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $draft = $pub->recordDraft($entry, self::BODY_TWO);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Launch', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        try {
            $service->transition($release, 'publish', $author);
            $this->fail('review was bypassed');
        } catch (ValidationException) {
            $this->assertNotSame($draft->headVersion, $entry->fresh()->published_version);
        }
        $service->transition($release, 'submit', $author);
        try {
            $service->transition($release, 'approve', $author);
            $this->fail('self-approval was admitted');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame('in_review', $release->fresh()->status);
        }
        $service->transition($release, 'approve', $reviewer);
        $later = $pub->recordDraft($entry->fresh(), self::BODY_ONE);
        $service->transition($release, 'publish', $reviewer);
        $this->assertSame('published', $release->fresh()->status);
        $this->assertSame($draft->headVersion, $entry->fresh()->published_version);
        $this->assertSame($later->headVersion, $pub->state($entry->fresh())->headVersion);
    }

    public function test_release_member_changes_reset_approval_and_take_down_requires_a_published_target(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Take down', $author);
        try {
            $service->putMember($release, $entry->id, null, $author, '/missing');
            $this->fail('unmanaged take-down was admitted');
        } catch (ValidationException) {
            $this->assertCount(0, $release->fresh()->members);
        }
        app(\Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry::class)->bind('page', \Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle::DEFINITION);
        $entry->forceFill(['workflow_marking' => 'published'])->save();
        try {
            $service->putMember($release, $entry->id, null, $author, '/missing');
            $this->fail('unknown redirect target was admitted');
        } catch (ValidationException) {
            $this->assertCount(0, $release->fresh()->members);
        }
        $target = $this->publishedPage(self::BODY_TWO);
        $target->forceFill(['segment' => '/target', 'workflow_marking' => 'published'])->save();
        $service->putMember($release, $entry->id, null, $author, $target->id);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->dropMember($release, $entry->id, $author);
        $this->assertSame('draft', $release->fresh()->status);
        $this->assertNull($release->fresh()->reviewed_by);
    }

    public function test_release_retains_its_artifact_and_rollback_creates_a_new_reviewable_release(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $oldPin = $entry->published_version;
        $pub = app(EntryPublication::class);
        $draft = $pub->recordDraft($entry, self::BODY_TWO);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Release', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        $pub->recordDraft($entry->fresh(), self::BODY_ONE);
        $this->assertTrue($this->artifacts()->disk()->exists($this->artifacts()->path($entry, $this->artifacts()->versionKey($draft->headVersion))));
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->transition($release, 'publish', $reviewer);
        $rollback = $service->rollback($release, $author);
        $this->assertNotSame($release->id, $rollback->id);
        $this->assertSame('draft', $rollback->status);
        $this->assertSame($oldPin, $rollback->members->first()->version_id);
        $this->assertSame($draft->headVersion, $entry->fresh()->published_version);
    }

    public function test_editor_publish_and_workflow_take_down_create_reviewed_releases_of_one(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $pin = $entry->fresh()->published_version;
        $request = Request::create('/', 'POST', ['ref' => $pin]);
        $state = EntryPublishOp::handle($entry, $request, $author);
        $this->assertNotNull($state->release);
        $this->assertSame('in_review', $state->release->status);
        $this->assertSame($pin, $entry->fresh()->published_version);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $service->transition($release, 'approve', $reviewer);
        $service->transition($release, 'publish', $reviewer);
        $this->assertSame('published', $release->fresh()->status);
        app(\Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry::class)->bind('page', \Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle::DEFINITION);
        $entry->forceFill(['workflow_marking' => 'published'])->save();
        $result = \Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp::handle($entry,
            Request::create('/', 'POST', ['transition' => 'unpublish']), $author);
        $response = \Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp::respond($result, $entry);
        $this->assertSame('published', $entry->fresh()->workflow_marking);
        $this->assertSame('in_review', $response->release->status);
        $takeDown = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($response->release->id);
        $service->transition($takeDown, 'approve', $reviewer);
        $service->transition($takeDown, 'publish', $reviewer);
        $this->assertNotSame('published', $entry->fresh()->workflow_marking);
    }

    public function test_release_without_calendars_refuses_schedule_but_can_publish_now(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Immediate', $author);
        $service->putMember($release, $entry->id, $entry->published_version, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        try {
            $service->schedule($release, '2030-01-01T00:00:00Z', 'UTC', $reviewer);
            $this->fail('schedule without calendars succeeded');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('calendars ActionService', $exception->getMessage());
            $this->assertSame('approved', $release->fresh()->status);
        }
        $service->transition($release, 'publish', $reviewer);
        $this->assertSame('published', $release->fresh()->status);
    }

    public function test_release_resource_and_commands_mount_through_the_existing_particle_surface(): void
    {
        $discovery = app(\Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery::class);
        foreach ([\Splicewire\Beam\Ux\Data\ReleaseData::class, \Splicewire\Beam\Ux\Particle\ReleaseCommandOp::class,
            \Splicewire\Beam\Ux\Particle\EntryReleaseCreateOp::class] as $class) {
            $discovery->registerClass($class);
        }
        \Splicewire\Beam\Facades\Particle::mount('beam-ux-releases', 'beam-ux-release')->only(['index', 'show'])->ops(['command']);
        \Splicewire\Beam\Facades\Particle::ops('beam-ux-entries', 'beam-ux-entry', 'create-release');
        $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();
        $this->assertContains('beam-ux-releases', $routes);
        $this->assertContains('beam-ux-releases/{id}/command', $routes);
        $this->assertContains('beam-ux-entries/{id}/create-release', $routes);
    }

    public function test_release_schedule_uses_the_existing_calendar_runner_and_rejects_a_revised_intent(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $draft = app(EntryPublication::class)->recordDraft($entry, self::BODY_TWO);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $this->calendarFixture();
        $release = $service->create($entry, 'Scheduled', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->schedule($release, '2030-01-01T00:00:00Z', 'UTC', $reviewer);
        $action = \Splicewire\Beam\Calendars\Models\CalendarAction::firstOrFail();
        $this->assertSame('scheduled', $release->fresh()->status);
        $service->rebase($release, $entry->id, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $attempt = app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($action->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-02T00:00:00Z'));
        $this->assertSame('blocked', $attempt->status);
        $this->assertSame($entry->published_version, $entry->fresh()->published_version);
        $service->schedule($release, '2030-01-02T00:00:00Z', 'UTC', $reviewer);
        $next = \Splicewire\Beam\Calendars\Models\CalendarAction::whereKeyNot($action->id)->firstOrFail();
        $attempt = app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($next->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-03T00:00:00Z'));
        $this->assertSame('applied', $attempt->status, json_encode($attempt->result));
        $this->assertSame($draft->headVersion, $entry->fresh()->published_version);
        $this->assertNull(app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($next->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-03T00:00:00Z')));
    }

    public function test_scheduled_release_rechecks_revoked_member_authority(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $draft = app(EntryPublication::class)->recordDraft($entry, self::BODY_TWO);
        $deny = false;
        [$service, $author, $reviewer] = $this->releaseFixture(function ($actor, $ability) use (&$deny) {
            return ! ($deny && $ability === 'publish');
        });
        $this->calendarFixture();
        $release = $service->create($entry, 'Later', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->schedule($release, '2030-01-01T00:00:00Z', 'UTC', $reviewer);
        $deny = true;
        $action = \Splicewire\Beam\Calendars\Models\CalendarAction::firstOrFail();
        $attempt = app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($action->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-02T00:00:00Z'));
        $this->assertSame('blocked', $attempt->status);
        $this->assertSame('scheduled', $release->fresh()->status);
        $this->assertSame($entry->published_version, $entry->fresh()->published_version);
    }

    private function calendarFixture(): void
    {
        $root = dirname((new ReflectionClass(\Splicewire\Beam\Calendars\Actions\ActionService::class))->getFileName(), 3);
        foreach (['create_calendar_actions_table', 'create_calendar_action_attempts_table'] as $name) {
            (require $root.'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        config(['beam.calendars.action_handlers' => ['kind.workflow-transition' => \Splicewire\Beam\Workflows\Calendar\WorkflowActionHandler::class]]);
        $this->app->singleton(\Splicewire\Beam\Calendars\Actions\ActionService::class);
        $this->app->instance(\Splicewire\Beam\Calendars\Contracts\ActionContextProvider::class,
            new class implements \Splicewire\Beam\Calendars\Contracts\ActionContextProvider
            {
                public function current(): \Splicewire\Beam\Calendars\Actions\ActionContext
                {
                    return new \Splicewire\Beam\Calendars\Actions\ActionContext('user:reviewer', 'user:reviewer', 'tenant:test');
                }
            });
        // The host authenticates the tenant context; Release participation still rechecks every member gate.
        $this->app->instance(\Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority::class,
            new class implements \Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority
            {
                public function authorize(\Illuminate\Database\Eloquent\Model $subject, string $transition, \Splicewire\Beam\Workflows\Actions\WorkflowActionContext $context): void {}
            });
    }

    public function test_release_rechecks_publish_authority_on_every_member_and_refuses_atomically(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $pub = app(EntryPublication::class);
        $refs = [$one->id => $pub->recordDraft($one, self::BODY_TWO)->headVersion,
            $two->id => $pub->recordDraft($two, self::BODY_ONE)->headVersion];
        $deny = false;
        [$service, $author, $reviewer] = $this->releaseFixture(function ($actor, $ability, $arguments) use (&$deny, $two) {
            return ! ($deny && $ability === 'publish' && ($arguments[0]->id ?? null) === $two->id);
        });
        $release = $service->create($one, 'Both', $author);
        foreach ($refs as $id => $ref) {
            $service->putMember($release, $id, $ref, $author);
        }
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $deny = true;
        try {
            $service->transition($release, 'publish', $reviewer);
            $this->fail('revoked member authority was ignored');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame('approved', $release->fresh()->status);
            $this->assertSame($one->published_version, $one->fresh()->published_version);
            $this->assertSame($two->published_version, $two->fresh()->published_version);
        }
    }

    private function releaseFixture(?\Closure $authorize = null, bool $reviewedAndMapped = true, bool $standardGuardProvider = false): array
    {
        // Most cases here are about REVIEWED Releases and the abilities a host maps: opt in to review, and map
        // `update` and `publish` so they are checked strictly through the Gate. The owner-ruled defaults (review off,
        // abilities unmapped) pass `$reviewedAndMapped = false`, which also installs no blanket Gate::before, so
        // each of those cases declares its own gates.
        if ($reviewedAndMapped) {
            config([
                'beam.ux.releases.review_required' => true,
                'beam.ux.releases.edit_ability' => 'update',
                'beam.ux.releases.publish_ability' => 'publish',
            ]);
        }
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table'] as $stub) {
            (require __DIR__."/../database/migrations/shared/{$stub}.php.stub")->up();
        }
        $author = new \Illuminate\Foundation\Auth\User;
        $author->setKeyType('string');
        $author->forceFill(['id' => 'author']);
        $reviewer = new \Illuminate\Foundation\Auth\User;
        $reviewer->setKeyType('string');
        $reviewer->forceFill(['id' => 'reviewer']);
        if ($reviewedAndMapped) {
            \Illuminate\Support\Facades\Gate::before($authorize ?? fn () => true);
        }
        $provider = \Mockery::mock(\Illuminate\Contracts\Auth\UserProvider::class);
        $provider->shouldReceive('retrieveById')->andReturnUsing(fn ($id) => $id === 'author' ? $author : ($id === 'reviewer' ? $reviewer : null));
        $guard = \Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
        $guard->shouldReceive('user')->andReturn(null);
        $guard->shouldReceive('id')->andReturn(null);
        if ($standardGuardProvider) {
            config([
                'auth.defaults.guard' => 'web',
                'auth.defaults.provider' => null,
                'auth.guards.web.provider' => 'users',
            ]);
            \Illuminate\Support\Facades\Auth::shouldReceive('createUserProvider')->with('users')->andReturn($provider);
        } else {
            \Illuminate\Support\Facades\Auth::shouldReceive('createUserProvider')->andReturn($provider);
        }
        \Illuminate\Support\Facades\Auth::shouldReceive('guard')->andReturn($guard);

        return [app(\Splicewire\Beam\Ux\Release\ReleaseService::class), $author, $reviewer];
    }

    // ---- The owner ruling (2026-10-18, launch ticket 00 `5dd27af98`): review off by default, and an upgrade never
    // makes Publish answer 403 to an author. Pre-Releases, the editor's Publish was gated by `ux.author` alone, so the
    // fallback is parity with that, not a widening.

    /** A Publish request for the entry's newest draft, through the editor's operation. */
    private function publishDraftAs(BeamUxEntry $entry, \Illuminate\Contracts\Auth\Authenticatable $actor): EntryPublicationData
    {
        $draft = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        return EntryPublishOp::handle($entry->fresh(), Request::create('/', 'POST', ['ref' => $draft->headVersion]), $actor);
    }

    public function test_by_default_a_sole_author_publish_publishes_at_once(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('published', $state->release->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_a_release_resolves_its_author_from_the_default_guards_provider(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false, standardGuardProvider: true);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('published', $state->release->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_a_host_that_opts_into_review_holds_a_publish_for_a_second_author(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        $pin = $entry->fresh()->published_version;

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('in_review', $state->release->status);
        $this->assertSame($pin, $entry->fresh()->published_version);
    }

    public function test_a_host_that_defines_no_entry_ability_never_refuses_an_author(): void
    {
        // audiostud's shape: a global `update` for its own models only, and no `publish`.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null, $subject = null) => $subject instanceof BeamParticle);

        $this->assertSame('published', $this->publishDraftAs($entry, $author)->release->status);
    }

    public function test_a_non_author_is_still_refused(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => false);
        $pin = $entry->fresh()->published_version;

        try {
            $this->publishDraftAs($entry, $author);
            $this->fail('A non-author published.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame($pin, $entry->fresh()->published_version);
        }
    }

    public function test_a_declared_entry_policy_that_denies_publish_still_refuses(): void
    {
        // The fallback never overrides a policy the host declared for BeamUxEntry.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, DenyPublishEntryPolicy::class);
        $pin = $entry->fresh()->published_version;

        try {
            $this->publishDraftAs($entry, $author);
            $this->fail('A declared policy that denies publish was overridden.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame($pin, $entry->fresh()->published_version);
        }
    }

    public function test_a_declared_entry_policy_without_publish_falls_back_to_its_update(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, DenyUpdateEntryPolicy::class);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->publishDraftAs($entry, $author);
    }

    public function test_direct_publish_resolves_one_ability_so_a_policy_that_allows_publish_governs(): void
    {
        // orch-peer HIGH on b23e933: the editor's Publish (a Release of one) must decide on ONE ability, the publish
        // ladder (publish, else update, else ux.author). A host policy with publish=true and update=false publishes;
        // checking edit first would refuse before the specific publish decision could govern.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('published', $state->release->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_a_multi_entry_release_still_checks_edit_on_its_member_operations(): void
    {
        // Only the direct Publish takes the single-ability path; building a Release by hand keeps the edit check.
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->create($entry, 'By hand', $author);
    }

    public function test_a_reviewed_direct_release_keeps_the_single_publish_ability_through_approve_and_publish(): void
    {
        // orch-peer / orch-lead 13:42Z: with review opted in, the later approve and publish of a direct Release of one
        // must decide on the same single publish ability; the provenance lives on the Release row, not in process state.
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $state = $this->publishDraftAs($entry, $author);
        $this->assertSame('in_review', $state->release->status);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $this->assertSame(\Splicewire\Beam\Ux\Release\ReleaseOrigin::Direct, $release->origin);

        // A fresh service, as a later request would resolve it.
        $later = app(\Splicewire\Beam\Ux\Release\ReleaseService::class);
        $later->transition($release, 'approve', $reviewer);
        $later->transition($release, 'publish', $reviewer);

        $this->assertSame('published', $release->fresh()->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_direct_and_built_releases_keep_their_own_gates_side_by_side(): void
    {
        // Isolation: the same actor and policy, a direct Release in review next to Releases built by hand. Nothing a
        // direct request does leaks into a built Release, and a member added by hand to a direct Release is an edit.
        $entry = $this->publishedPage(self::BODY_ONE);
        $other = $this->publishedPage(self::BODY_TWO);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $direct = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($this->publishDraftAs($entry, $author)->release->id);
        $this->assertSame(\Splicewire\Beam\Ux\Release\ReleaseOrigin::Direct, $direct->origin);

        try {
            $service->create($entry, 'By hand', $author);
            $this->fail('A built Release borrowed the direct gate.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }

        try {
            $service->putMember($direct, $other->id, app(EntryPublication::class)->state($other->fresh())->headVersion, $author);
            $this->fail('A member added by hand to a direct Release skipped the edit check.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }

        $this->assertSame(1, $direct->members()->count());
        $this->assertSame(1, \Splicewire\Beam\Ux\Models\BeamUxRelease::count());
    }

    public function test_an_emptied_direct_release_refilled_by_hand_is_an_edit(): void
    {
        // orch-peer HIGH on 980e865: a direct Release in review loses its member through a legitimate edit; refilling it
        // by hand is an edit too, so a publisher without `update` is refused. Only requestOne's own first placement
        // takes the publish ladder.
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, ToggleUpdateEntryPolicy::class);
        ToggleUpdateEntryPolicy::$update = true;

        $state = $this->publishDraftAs($entry, $author);
        $direct = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $service->dropMember($direct, $entry->id, $author);
        $this->assertSame(0, $direct->members()->count());

        ToggleUpdateEntryPolicy::$update = false;
        try {
            $service->putMember($direct, $entry->id, $state->headVersion, $author);
            $this->fail('A hand-added member of an emptied direct Release skipped the edit check.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame(0, $direct->members()->count());
        } finally {
            ToggleUpdateEntryPolicy::$update = true;
        }
    }

    public function test_the_origin_alter_reaches_a_releases_table_made_by_the_earlier_create_and_is_idempotent(): void
    {
        // orch-peer HIGH on 980e865: `origin` arrives as its own ALTER stub, because editing the create reaches nothing
        // already migrated. A table from the create alone gains the column, an existing Release reads as built, and a
        // second run changes nothing. (A fresh install runs the same two stubs.)
        Schema::dropIfExists('beam_ux_release_members');
        Schema::dropIfExists('beam_ux_releases');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
        $this->assertFalse(Schema::hasColumn('beam_ux_releases', 'origin'));
        \Illuminate\Support\Facades\DB::table('beam_ux_releases')->insert([
            'id' => '00000000-0000-0000-0000-000000000001', 'title' => 'Earlier', 'realm' => 'site',
            'created_by' => 'user:1', 'edited_by' => 'user:1', 'status' => 'draft',
        ]);

        $alter = __DIR__.'/../database/migrations/shared/add_origin_to_beam_ux_releases_table.php.stub';
        (require $alter)->up();
        (require $alter)->up();

        $this->assertTrue(Schema::hasColumn('beam_ux_releases', 'origin'));
        $this->assertSame(
            \Splicewire\Beam\Ux\Release\ReleaseOrigin::Built,
            \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail('00000000-0000-0000-0000-000000000001')->origin,
        );
    }

    public function test_a_caller_cannot_claim_the_direct_origin(): void
    {
        // orch-peer HIGH on 980e865: public create() keeps its three-argument, built-only shape; only requestOne opens a
        // Direct Release, and the stored value is constrained by the ReleaseOrigin cast.
        $create = new ReflectionClass(\Splicewire\Beam\Ux\Release\ReleaseService::class);
        $this->assertCount(3, $create->getMethod('create')->getParameters());
        $this->assertFalse($create->getMethod('open')->isPublic());
        $this->assertFalse($create->getMethod('place')->isPublic());

        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture();
        $built = $service->create($entry, 'By hand', $author);
        $this->assertSame(\Splicewire\Beam\Ux\Release\ReleaseOrigin::Built, $built->fresh()->origin);

        \Illuminate\Support\Facades\DB::table('beam_ux_releases')->where('id', $built->id)->update(['origin' => 'direct-ish']);
        $this->expectException(\ValueError::class);
        $built->fresh()->origin;
    }

    public function test_save_draft_keeps_its_pre_releases_gate_and_never_consults_an_entry_policy(): void
    {
        // orch-lead 13:14Z: every operation keeps exactly its pre-Releases authorization. At 86d4b98 save-draft was
        // `ux.author`, subject-free (`abilityModel: false`, pinned by
        // test_every_operation_declares_its_shape_slots_and_the_one_entitlement_plane), and its write gate consulted
        // no policy. So an author whom an entry `update` would deny still saves a draft, before and after this change.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);
        $head = app(EntryPublication::class)->state($entry->fresh())->headVersion;

        $state = EntryDraftSaveOp::handle($entry->fresh(), Request::create('/', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => $head]), $author);

        $this->assertNotSame($head, $state->headVersion);
    }

    public function test_release_batch_compile_failure_keeps_every_pin_and_public_artifact(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $pub = app(EntryPublication::class);
        $before = [$one->published_version, $two->published_version];
        $oldArtifacts = [$this->artifacts()->read($one), $this->artifacts()->read($two)];
        $a = $pub->recordDraft($one, self::BODY_TWO);
        $b = $pub->recordDraft($two, self::BODY_ONE);
        $this->artifacts()->forgetVersion($two, $b->headVersion);
        PublicationFakeCompiler::$fails = true;
        try {
            $pub->publishVersions($one, [$one->id => $a->headVersion, $two->id => $b->headVersion]);
            $this->fail('failed compilation published a Release');
        } catch (CompilationFailed) {
            $this->assertSame($before, [$one->fresh()->published_version, $two->fresh()->published_version]);
            $this->assertSame($oldArtifacts, [$this->artifacts()->read($one->fresh()), $this->artifacts()->read($two->fresh())]);
        } finally {
            PublicationFakeCompiler::$fails = false;
        }
    }

    public function test_release_batch_refuses_a_stale_pin_before_any_member_moves(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $pub = app(EntryPublication::class);
        $pins = [$one->id => $one->published_version, $two->id => $two->published_version];
        $a = $pub->recordDraft($one, self::BODY_TWO);
        $b = $pub->recordDraft($two, self::BODY_ONE);
        $pub->publishVersion($two, $b->headVersion);
        try {
            $pub->publishVersions($one, [$one->id => $a->headVersion, $two->id => $b->headVersion], expectedPins: $pins);
            $this->fail('stale Release was published');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('release', $exception->errors());
            $this->assertSame($pins[$one->id], $one->fresh()->published_version);
            $this->assertSame($b->headVersion, $two->fresh()->published_version);
        }
    }

    public function test_page_settings_are_drafted_with_body_and_only_published_from_the_named_version(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $entry->forceFill(['title' => 'Published title'])->save();
        $publication = app(EntryPublication::class);
        $base = $publication->state($entry)->headVersion;
        $draft = EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', [
            'body' => self::BODY_TWO, 'baseVersion' => $base,
            'pageSettings' => ['title' => 'Draft title', 'parentId' => null, 'segment' => 'draft-page'],
        ]), null);
        $this->assertSame('Published title', $entry->fresh()->title);
        $particle = $entry->fresh()->particle;
        $version = app(\Rushing\Versioning\Contracts\VersionStore::class)->version($particle, $draft->headVersion);
        $this->assertSame('Draft title', $version->snapshot['meta']['pageSettings']['title'] ?? null);
        $publication->publishVersion($entry->fresh(), $draft->headVersion);
        $this->assertSame('Draft title', $entry->fresh()->title);
        $this->assertSame('draft-page', $entry->fresh()->segment);
    }

    public function test_restoring_page_settings_keeps_live_fields_until_publish_and_first_date_is_stable(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $first = $pub->draftFromBase($entry, self::BODY_TWO, $pub->state($entry)->headVersion, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'First', segment: 'first'));
        $pub->publishVersion($entry->fresh(), $first->headVersion);
        $firstDate = $entry->fresh()->first_published_at;
        $this->assertNotNull($firstDate);
        $second = $pub->draftFromBase($entry->fresh(), self::BODY_ONE, $first->headVersion, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'Second', segment: 'second'));
        $pub->publishVersion($entry->fresh(), $second->headVersion);
        $restored = $pub->restore($entry->fresh(), $first->headVersion);
        $this->assertSame('Second', $entry->fresh()->title);
        $pub->publishVersion($entry->fresh(), $restored->headVersion);
        $this->assertSame('First', $entry->fresh()->title);
        $this->assertSame($firstDate, $entry->fresh()->first_published_at);
    }

    public function test_entry_form_cannot_change_a_published_pages_settings_outside_history(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $request = Request::create('/entries/'.$entry->id, 'PATCH');
        $route = new \Illuminate\Routing\Route(['PATCH'], 'entries/{id}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        $input = \Splicewire\Beam\Ux\Data\BeamUxEntryInputData::fromModel($entry);
        $input->title = 'Direct live overwrite';
        $this->expectException(ValidationException::class);
        $input->toModelAttributes();
    }

    public function test_batch_url_check_accepts_a_swap_but_refuses_a_collision(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $one->forceFill(['segment' => 'one'])->save();
        $two->forceFill(['segment' => 'two'])->save();
        $check = app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class);
        $check->assertAllowed($one, [
            $one->id => ['parentId' => null, 'segment' => 'two'],
            $two->id => ['parentId' => null, 'segment' => 'one'],
        ]);
        $this->assertSame('one', $one->fresh()->segment);
        $this->expectException(ValidationException::class);
        $check->assertAllowed($one, [$one->id => ['parentId' => null, 'segment' => 'two']]);
    }

    public function test_batch_url_check_refuses_an_unpublished_parent(): void
    {
        $child = $this->publishedPage(self::BODY_ONE);
        $parent = $this->pageWithUnversionedBody(self::BODY_TWO);
        $this->expectException(ValidationException::class);
        app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class)->assertAllowed($child, [
            $child->id => ['parentId' => $parent->id, 'segment' => 'child'],
        ]);
    }

    public function test_batch_includes_a_new_parent_and_uses_the_existing_absolute_url_grammar(): void
    {
        $parent = $this->pageWithUnversionedBody(self::BODY_ONE);
        $child = $this->publishedPage(self::BODY_TWO);
        $other = $this->publishedPage(self::BODY_ONE);
        $other->forceFill(['segment' => '/taken'])->save();
        $check = app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class);
        $check->assertAllowed($child, [
            $parent->id => ['parentId' => null, 'segment' => 'parent'],
            $child->id => ['parentId' => $parent->id, 'segment' => './child'],
        ]);
        $this->expectException(ValidationException::class);
        $check->assertAllowed($child, [
            $parent->id => ['parentId' => null, 'segment' => 'parent'],
            $child->id => ['parentId' => $parent->id, 'segment' => '/taken'],
        ]);
    }

    public function test_batch_refuses_a_cycle_without_mutating_rows(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        try {
            app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class)->assertAllowed($one, [
                $one->id => ['parentId' => $two->id, 'segment' => 'one'],
                $two->id => ['parentId' => $one->id, 'segment' => 'two'],
            ]);
            $this->fail('cycle was admitted');
        } catch (ValidationException) {
            $this->assertNull($one->fresh()->parent_id);
            $this->assertNull($two->fresh()->parent_id);
        }
    }

    public function test_body_only_save_preserves_the_working_page_settings(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $first = $pub->draftFromBase($entry, self::BODY_TWO, $pub->state($entry)->headVersion, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'Draft', segment: 'draft'));
        $next = $pub->recordDraft($entry->fresh(), self::BODY_ONE);
        $pub->publishVersion($entry->fresh(), $next->headVersion);
        $this->assertSame('Draft', $entry->fresh()->title);
        $this->assertSame('draft', $entry->fresh()->segment);
    }

    public function test_invalid_page_settings_refuse_before_recording_a_draft(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $before = $pub->state($entry)->headVersion;
        try {
            EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', [
                'body' => self::BODY_TWO, 'baseVersion' => $before,
                'pageSettings' => ['title' => 'Page', 'pageType' => 'InventedType'],
            ]), null);
            $this->fail('invalid page type was accepted');
        } catch (ValidationException) {
            $this->assertSame($before, $pub->state($entry->fresh())->headVersion);
        }
    }

    public function test_page_settings_on_a_component_refuse_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $entry->type = UxType::Component;
        $entry->save();
        $publication = app(EntryPublication::class);
        $before = $publication->state($entry)->headVersion;
        try {
            $publication->draftFromBase($entry, self::BODY_TWO, $before, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'Not a page'));
            $this->fail('component accepted page settings');
        } catch (ValidationException) {
            $this->assertSame($before, $publication->state($entry->fresh())->headVersion);
        }
    }

    public function test_editor_draft_refuses_a_stale_base_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $base = $publication->state($entry)->headVersion;
        $first = EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => $base]), null);
        $this->assertNotSame($base, $first->headVersion);
        try {
            EntryDraftSaveOp::handle($entry->fresh(), Request::create('/', 'POST', ['body' => self::BODY_ONE, 'baseVersion' => $base]), null);
            $this->fail('stale editor save was accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            $this->assertStringContainsString('base', $e->getMessage());
        }
        $this->assertSame($first->headVersion, $publication->state($entry->fresh())->headVersion);
        $this->assertCount(2, $publication->state($entry->fresh())->versions);
        $this->assertSame(self::BODY_TWO, $entry->fresh()->particle->payload);
    }

    public function test_next_author_write_migrates_legacy_style_to_the_versioned_block_field(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $body = self::BODY_ONE;
        $body[0]['props'] = [['name' => 'style', 'kind' => 'string', 'value' => 'color:red']];
        $written = app(EntryPublication::class)->write($entry, $body);
        $this->assertSame(['color' => 'red'], $written['body'][0]['style']['layers'][0]['declarations']);
        $this->assertSame([], $written['body'][0]['props']);
    }

    public function test_named_publish_pins_the_seen_version_without_replacing_a_newer_working_head(): void
    {
        [, $author] = $this->releaseFixture();
        config(['beam.ux.releases.review_required' => false]);
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $seen = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $later = $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $published = EntryPublishOp::handle($entry->fresh(), Request::create('/', 'POST', ['ref' => $seen->headVersion]), $author);
        $this->assertSame($seen->headVersion, $published->publishedVersion);
        $this->assertSame($later->headVersion, $published->headVersion);
        $this->assertTrue($published->draftPending);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, $entry->fresh()->particle->payload);
    }

    public function test_ep10_save_compiles_its_version_and_preserves_the_public_artifact(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);
        $draft = clone $entry->fresh();
        $draft->published_version = $state->headVersion;
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($draft));
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_ep10_prunes_only_obsolete_artifacts_and_restore_recompiles_its_draft(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $first = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $firstPath = $this->artifacts()->path($entry, $this->artifacts()->versionKey($first->headVersion));
        $this->assertTrue($this->artifacts()->disk()->exists($firstPath));
        $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $this->assertFalse($this->artifacts()->disk()->exists($firstPath));
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $restored = $publication->restore($entry->fresh(), $first->headVersion);
        $restoredPath = $this->artifacts()->path($entry, $this->artifacts()->versionKey($restored->headVersion));
        $this->assertStringContainsString('Two', $this->artifacts()->disk()->get($restoredPath));
        $this->assertSame($first->publishedVersion, $restored->publishedVersion);
        $this->assertTrue($restored->draftPending);
    }

    public function test_ep10_publish_of_missing_artifact_compiles_the_named_snapshot_not_the_head(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $first = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $latest = $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $state = $publication->publishVersion($entry->fresh(), $first->headVersion);
        $this->assertSame($first->headVersion, $state->publishedVersion);
        $this->assertSame($latest->headVersion, $state->headVersion);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, BeamParticle::find($entry->particle_id)->payload);
    }

    public function test_ep10_backfill_uses_the_pin_snapshot_and_keeps_the_draft_artifact(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);
        app(CompileEntryBody::class)->forEntry($entry->fresh(), force: true);
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $draftPath = $this->artifacts()->path($entry, $this->artifacts()->versionKey($state->headVersion));
        $this->assertStringContainsString('Two', $this->artifacts()->disk()->get($draftPath));
    }

    public function test_ep10_programmatic_save_retains_body_but_does_not_publish_a_failed_compile(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pin = $entry->published_version;
        PublicationFakeCompiler::$fails = true;
        try {
            try {
                EntryBodySaveOp::handle($entry->fresh(), Request::create('/', 'POST', ['body' => self::BODY_TWO]), null);
                $this->fail('Programmatic save published an uncompilable version.');
            } catch (CompilationFailed $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame(self::BODY_TWO, BeamParticle::find($entry->particle_id)->payload);
            $this->assertSame($pin, $entry->fresh()->published_version);
            $this->assertTrue(app(EntryPublication::class)->state($entry->fresh())->draftPending);
        } finally {
            PublicationFakeCompiler::$fails = false;
        }
    }

    public function test_ep10_failed_draft_compile_keeps_the_draft_and_publish_refuses(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pin = $entry->published_version;
        $publication = app(EntryPublication::class);
        PublicationFakeCompiler::$fails = true;
        try {
            $state = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
            $this->assertNotNull($state->compileError);
            $this->assertTrue($state->draftPending);
            $this->assertSame(self::BODY_TWO, BeamParticle::find($entry->particle_id)->payload);
            try {
                $publication->publishVersion($entry->fresh(), $state->headVersion);
                $this->fail('Publish accepted an uncompilable version');
            } catch (CompilationFailed $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame($pin, $entry->fresh()->published_version);
            $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        } finally {
            PublicationFakeCompiler::$fails = false;
        }
    }

    public function test_a_draft_is_recorded_as_a_version_and_the_reader_keeps_the_published_body(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publishedPath = $this->artifacts()->path($entry->fresh());

        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO, 'my draft');

        $this->assertTrue($state->draftPending, 'the two pins agree after a draft');
        $this->assertNotSame($state->headVersion, $state->publishedVersion);
        $this->assertCount(2, $state->versions);
        $this->assertSame('my draft', $state->versions[0]->label);
        $this->assertTrue($state->versions[0]->isHead);
        $this->assertFalse($state->versions[0]->isHead && $state->versions[0]->isPublished);

        // The reader's whole answer: same address, same module, and it is the PREVIOUS body.
        $fresh = $entry->fresh();
        $this->assertSame($publishedPath, $this->artifacts()->path($fresh), 'a draft moved the public artifact address');
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($fresh));
        $this->assertStringNotContainsString('Two', (string) $this->artifacts()->read($fresh));
    }

    public function test_publishing_moves_the_pin_and_compiles_the_drafted_body_at_its_new_address(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);

        $state = $publication->publish($entry->fresh());

        $this->assertFalse($state->draftPending);
        $this->assertSame($state->headVersion, $state->publishedVersion, 'publish pins the working HEAD');
        $this->assertNull($state->compileError);
        // A publish straight after a draft pins THAT draft rather than minting a twin of it.
        $this->assertCount(2, $state->versions);

        $fresh = $entry->fresh();
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($fresh));
    }

    public function test_the_first_draft_baselines_the_already_live_body_so_it_stays_restorable(): void
    {
        // The case every entry authored before this path is in: a body, an artifact, and no history.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $this->assertSame(0, $this->versionRows($entry));

        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        $this->assertCount(2, $state->versions, 'the live body was not recorded before the draft overwrote it');
        $baseline = $state->versions[1];
        $this->assertSame('baseline', $baseline->label);
        $this->assertTrue($baseline->isPublished, 'the baseline is what the reader is on');
        // And the reader is still reading it, at the address the pin now names.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_baseline_that_fails_after_its_snapshot_rolls_the_snapshot_back_and_a_retry_pins(): void
    {
        // review-r1 R2 (seeded pages, 2026-10-16): baseline() snapshotted (committing a version and HEAD), THEN saved the
        // pin. A failure in between left a history-bearing, pin-less row, which every later baseline() treats as an
        // unpublished draft and skips, forever. The failure here lands on the pin save, AFTER the snapshot.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            app(EntryPublication::class)->baseline($entry->fresh());
            $this->fail('a failed pin save was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame(0, $this->versionRows($entry), 'the snapshot outlived the failed pin: the row now reads as a draft');
        $this->assertNull($entry->fresh()->published_version);

        // The retry is not a no-op: the row still has no history, so baseline() pins it.
        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertNotNull($entry->fresh()->published_version, 'a retry could not repair the row');
        $this->assertSame(1, $this->versionRows($entry));
    }

    public function test_a_central_entry_under_a_tenant_default_rolls_its_snapshot_back_with_a_failed_pin_and_a_retry_pins(): void
    {
        // review-r1 R3 (2026-10-16): the supported central-entry shape, where EntryPromoter keeps the entry and its
        // particle on `central` while the default connection is a tenant's. baseline()'s transaction is on the entry's
        // connection; the version store wrote the version on the DEFAULT one, outside it, so a failed pin kept the
        // version and the row read as a draft forever. Needs rushing/laravel-versioning with record-connection versions.
        config(['database.connections.central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->createTables('central');

        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        // Built the way EntryPromoter builds one: the particle written with its own central connection, then the entry.
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(
            (new BeamParticle)->setConnection('central'),
            self::BODY_ONE,
        );
        $entry = BeamUxEntry::on('central')->create(['slug' => 'central-home', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => $particle->id]);
        $this->assertSame('central', $entry->getConnectionName());
        $this->assertSame(1, \DB::connection('central')->table(Beam::table('particles'))->count(), 'the body left the entry connection');

        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            app(EntryPublication::class)->baseline($entry);
            $this->fail('a failed pin save was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame(0, \DB::connection('central')->table(Beam::table('versions'))->count(), 'the snapshot outlived the failed pin on central');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'the snapshot outlived the failed pin on the tenant default');
        $this->assertNull($entry->fresh()->published_version);

        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertNotNull($entry->fresh()->published_version, 'a retry could not repair the central row');
        $this->assertSame(1, \DB::connection('central')->table(Beam::table('versions'))->count());
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count());
    }

    public function test_restoring_rolls_forward_into_a_draft_and_the_reader_keeps_the_published_body(): void
    {
        // ADR-0225 decision 5: Restore applies the frozen snapshot to the working copy and mints a new version, and does
        // NOT publish. Rolling back the live page takes Restore and then Publish (review-r1, launch L3, 2026-10-16).
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $published = $publication->publish($entry->fresh());
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));

        $first = $published->versions[array_key_last($published->versions)];

        $state = $publication->restore($entry->fresh(), $first->readable);

        // FORWARD: the history grew, the restored version is still in it, and HEAD is the new row: a DRAFT.
        $this->assertCount(3, $state->versions);
        $this->assertSame("restore of {$first->readable}", $state->versions[0]->label);
        $this->assertTrue($state->versions[0]->isHead);
        $this->assertFalse($state->versions[0]->isPublished, 'a restore published the restored body');
        $this->assertTrue($state->draftPending);
        $this->assertSame($published->publishedVersion, $state->publishedVersion, 'a restore moved the publication pin');

        // The reader still gets the published body; the working copy is the restored one.
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, BeamParticle::find($entry->fresh()->particle_id)->payload);

        // Publish is what moves the reader.
        $publication->publish($entry->fresh());
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_publishing_a_restored_empty_document_retires_the_artifact_rather_than_serving_an_empty_module(): void
    {
        // beam-ux d033815: a cleared document is no body, so the reader must read UNAUTHORED (no
        // artifact at any address) rather than be handed a module that exports nothing. Restoring an empty
        // version only drafts it (ADR-0225 decision 5); PUBLISHING that draft is what retires the artifact,
        // and it must land in the same place as publishing a cleared body directly.
        $entry = $this->publishedPage([]);
        $publication = app(EntryPublication::class);
        $cleared = $publication->publish($entry->fresh());
        $empty = $cleared->versions[array_key_last($cleared->versions)];

        $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $publication->publish($entry->fresh());
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $publication->restore($entry->fresh(), $empty->readable);

        // ADR-0225 decision 5: the restore is a draft, so the reader keeps the published body until Publish.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $publication->publish($entry->fresh());

        $this->assertNull($this->artifacts()->read($entry->fresh()), 'an empty restore, published, left a module behind');
    }

    public function test_an_immediate_save_still_publishes_and_now_records_the_history_it_never_had(): void
    {
        // The contract `g2-beam-author-entry` proves: a save through this op is live when it returns.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);

        $payload = EntryBodySaveOp::handle($entry, $this->saveRequest(self::BODY_TWO), actor: null);

        $this->assertNull($payload['compileError']);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));

        $state = app(EntryPublication::class)->state($entry->fresh());
        $this->assertFalse($state->draftPending, 'an immediate save left a draft pending');
        // Two: the body that was live before the save (baselined), and the one it wrote.
        $this->assertCount(2, $state->versions);
        $this->assertTrue($state->versions[0]->isPublished);
    }

    public function test_a_ref_that_names_no_version_of_this_entry_is_a_422_on_ref(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);

        try {
            app(EntryPublication::class)->restore($entry->fresh(), 'v99');
            $this->fail('an unknown ref restored something');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ref', $e->errors());
        }

        // And nothing moved: the reader is where they were.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_draft_refuses_a_canvas_document_on_a_format_that_cannot_carry_one(): void
    {
        // The mdx defect (G2-BEAM-AUTHOR-ENTRY, 2026-09-11) reached through the draft door would land
        // the damage at draft time and surface it at publish time, which is strictly worse.
        $entry = BeamUxEntry::create(['slug' => 'docs', 'type' => UxType::Page->value, 'format' => 'mdx']);

        try {
            app(EntryPublication::class)->recordDraft($entry, self::BODY_ONE);
            $this->fail('a canvas document was drafted onto an mdx entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
            $this->assertStringContainsString('mdx', $e->errors()['body'][0]);
        }

        $this->assertNull($entry->fresh()->particle_id, 'the refused draft still bound a particle');
    }

    public function test_publish_reuses_the_saved_artifact_without_invoking_a_now_failing_compiler(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        PublicationFakeCompiler::$fails = true;

        try {
            $state = app(EntryPublication::class)->publish($entry->fresh());
        } finally {
            PublicationFakeCompiler::$fails = false;
        }

        $this->assertNull($state->compileError);
        $this->assertFalse($state->draftPending);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_the_artifact_address_follows_the_publication_pin_and_not_the_working_head(): void
    {
        // The mechanism the reader-visible cases above all rest on, asserted directly: the address is
        // the PUBLISHED version, so a moving HEAD cannot move it.
        $entry = $this->publishedPage(self::BODY_ONE);
        $pinned = $this->artifacts()->path($entry->fresh());

        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh->getAttribute('published_version'));
        $this->assertNotSame(
            (string) $fresh->getAttribute('published_version'),
            (string) $fresh->particle->getAttribute('head_version'),
            'HEAD did not move away from the pin, so this case proves nothing',
        );
        $this->assertSame($pinned, $this->artifacts()->path($fresh));
    }

    public function test_an_entry_with_no_pin_addresses_exactly_as_it_did_before_the_pin_existed(): void
    {
        // The degrade path: a host that has not migrated the column, or an entry never taken through
        // this door, must key on the particle write as it always did.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);

        $this->assertNull($entry->fresh()->getAttribute('published_version'));

        $expected = substr(hash('xxh128', '3:'.$entry->fresh()->particle->getAttribute('updated_at')->format('U.u')), 0, 16);
        $this->assertSame($expected, $this->artifacts()->version($entry->fresh()));
    }

    public function test_every_operation_declares_its_shape_slots_and_the_one_entitlement_plane(): void
    {
        $expected = [
            EntryDraftSaveOp::class => ['save-draft', OperationKind::Write],
            EntryPublishOp::class => ['publish', OperationKind::Write],
            EntryVersionsShowOp::class => ['versions', OperationKind::Read],
            EntryVersionRestoreOp::class => ['restore', OperationKind::Write],
        ];

        foreach ($expected as $class => [$name, $kind]) {
            $op = (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();

            $this->assertSame('beam-ux-entry', $op->resource, $class);
            $this->assertSame($name, $op->name, $class);
            $this->assertSame($kind, $op->kind, $class);
            // One plane for the whole family, subject-free (particle-operation-surface ticket 08): an
            // author who can publish but not list, or draft but not publish, has half an editor.
            $this->assertSame('ux.author', $op->ability, $class);
            $this->assertFalse($op->abilityModel, $class);
            $this->assertSame(EntryPublicationData::class, $op->output, $class);
        }
    }

    public function test_the_versions_read_projects_the_same_state_the_writes_return(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO, 'wip');

        $state = EntryVersionsShowOp::handle($entry->fresh(), new Request, actor: null);

        $this->assertInstanceOf(EntryPublicationData::class, $state);
        $this->assertTrue($state->draftPending);
        $this->assertSame('wip', $state->versions[0]->label);
        $this->assertSame((string) $entry->getKey(), $state->id);
    }

    public function test_the_publish_and_draft_operations_round_trip_through_their_declared_inputs(): void
    {
        [, $author] = $this->releaseFixture();
        config(['beam.ux.releases.review_required' => false]);
        $entry = $this->publishedPage(self::BODY_ONE);

        $drafted = EntryDraftSaveOp::handle(
            $entry->fresh(),
            Request::create('/x', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => app(EntryPublication::class)->state($entry->fresh())->headVersion, 'label' => 'from the op']),
            actor: null,
        );
        $this->assertInstanceOf(EntryPublicationData::class, $drafted);
        $this->assertTrue($drafted->draftPending);
        $this->assertSame('from the op', $drafted->versions[0]->label);

        $published = EntryPublishOp::handle($entry->fresh(), Request::create('/x', 'POST', ['ref' => $drafted->headVersion]), actor: $author);
        $this->assertFalse($published->draftPending);

        $restored = EntryVersionRestoreOp::handle(
            $entry->fresh(),
            Request::create('/x', 'POST', ['ref' => $published->versions[array_key_last($published->versions)]->readable]),
            actor: null,
        );
        // ADR-0225 decision 5: a draft; the reader keeps the published body (Two).
        $this->assertTrue($restored->draftPending);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_host_without_the_version_store_refuses_to_publish_and_names_the_fix(): void
    {
        // ADR-0225 decision 2: the pin is required wherever the editor is mounted. The pin-less branch
        // that used to mirror and compile at the unpinned address is gone; a save-body or publish on a
        // host missing the store refuses, naming both missing halves and the command that fixes it.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $artifactBefore = $this->artifacts()->read($entry->fresh());
        Schema::drop(Beam::table('versions'));

        try {
            EntryBodySaveOp::handle($entry->fresh(), $this->saveRequest(self::BODY_TWO), actor: null);
            $this->fail('save-body published pin-less on a host without the version store');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/beam-ux-migrations/', $e->getMessage());
            $this->assertMatchesRegularExpression('/beam_versions|versions\] table/', $e->getMessage());
        }
        // Refused BEFORE the write: the working copy is still the body that was live.
        $this->assertSame(self::BODY_ONE, $entry->fresh()->particle->getAttribute('payload'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vendor:publish/');
        try {
            app(EntryPublication::class)->publish($entry->fresh());
        } finally {
            // Nothing moved: the reader keeps exactly the artifact it had.
            $this->assertSame($artifactBefore, $this->artifacts()->read($entry->fresh()));
            $this->assertNull($entry->fresh()->getAttribute('published_version'));
        }
    }

    private function artifacts(): EntryArtifactStore
    {
        return app(EntryArtifactStore::class);
    }

    /** An entry with a body and an artifact, already taken through the publish path once. */
    private function publishedPage(array $body): BeamUxEntry
    {
        $entry = $this->pageWithUnversionedBody($body);

        app(EntryPublication::class)->publish($entry->fresh());

        return $entry->fresh();
    }

    private function bindSplitTenancy(string $central = 'central_db'): void
    {
        // The entry stays on the default (testing) connection, which is not this central connection, so
        // it is tenant-resident while its DB ops still work.
        $this->app->instance(TenancyContext::class, new class($central) implements TenancyContext
        {
            public function __construct(private string $central) {}

            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function currentTenant(): ?object
            {
                return null;
            }

            public function currentTenantId(): int|string|null
            {
                return 1;
            }

            public function centralConnection(): ?string
            {
                return $this->central;
            }
        });
    }

    /**
     * review-r1, EP-07a boundary-fix re-review (2026-10-17): Publish guards the working copy, which it must read
     * on the ENTRY's connection. Read on the default one, a tenant entry on its own connection showed the guard
     * nothing (`[]`), passed, and the real body was pinned and compiled unchecked.
     */
    public function test_publish_guards_the_working_copy_on_the_entrys_own_connection(): void
    {
        config(['database.connections.tenant_acme' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->createTables('tenant_acme');
        $this->bindSplitTenancy();

        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        // Code written straight to the particle, as history from before the save check, on the tenant's connection.
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(
            (new BeamParticle)->setConnection('tenant_acme'),
            [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']],
        );
        $entry = BeamUxEntry::on('tenant_acme')->create(['slug' => 'tenant-home', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => $particle->id]);
        $this->assertSame(0, \DB::table(Beam::table('particles'))->count(), 'the default connection must hold no body, or the probe proves nothing');

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('publish pinned a tenant body it never checked');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opaque', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version);
        $this->assertSame(0, \DB::connection('tenant_acme')->table(Beam::table('versions'))->count(), 'a refused publish recorded a version');
    }

    /**
     * review-r1 R2 (2026-10-17): publish() baselined first, and on an entry with no history baseline() pins,
     * mirrors and compiles the working copy itself, so code was published before the guard refused it. The
     * guard now runs before any write: a refused first publish leaves history, pin, artifact and mirror as they were.
     */
    public function test_a_refused_first_publish_writes_nothing(): void
    {
        Storage::fake('mirror');
        config(['beam.ux.storage.mirror_disk' => 'mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        // History-less, as a body written before the save check existed; then the entry is tenant-resident.
        $entry = BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new BeamParticle, [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        $this->bindSplitTenancy();
        $artifactsBefore = Storage::disk('artifacts')->allFiles();

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('a code-bearing first publish was not refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opaque', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version, 'the pin moved');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'history was written');
        $this->assertSame($artifactsBefore, Storage::disk('artifacts')->allFiles(), 'an artifact was compiled');
        $this->assertSame([], Storage::disk('mirror')->allFiles(), 'the body was mirrored');
    }

    /** The legality half of the same order: an illegal history-less body is checked against nothing published, not itself. */
    public function test_a_first_publish_of_an_illegal_body_is_refused(): void
    {
        Storage::fake('mirror');
        config(['beam.ux.storage.mirror_disk' => 'mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        $entry = BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new BeamParticle, [['kind' => 'block', 'id' => 'mq', 'name' => 'marquee', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        $this->bindSplitTenancy();
        $artifactsBefore = Storage::disk('artifacts')->allFiles();

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('an illegal first publish passed because it was compared with its own baseline');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('marquee', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version, 'the pin moved');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'history was written');
        $this->assertSame($artifactsBefore, Storage::disk('artifacts')->allFiles(), 'an artifact was compiled');
        $this->assertSame([], Storage::disk('mirror')->allFiles(), 'the body was mirrored');
    }

    public function test_a_tenant_save_draft_with_an_opaque_node_is_refused_at_the_choke(): void
    {
        // HIGH-1: save-draft -> recordDraft -> write(), the choke. A tenant entry refuses an opaque node
        // (code) even though EntryDraftSaveOp itself calls no guard.
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']], 'draft');
    }

    public function test_a_tenant_save_draft_with_an_expression_prop_is_refused_at_the_choke(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->recordDraft($entry->fresh(), [[
            'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
            'props' => [['name' => 'x', 'kind' => 'expression', 'value' => 'user.isAdmin']], 'children' => [],
        ]], 'draft');
    }

    public function test_a_restore_of_a_code_bearing_version_on_a_tenant_entry_is_refused(): void
    {
        // A code-bearing version recorded while central, then the entry is tenant-owned: restore refuses
        // it BEFORE applying (ADR-0227 d1), so a tenant cannot republish old code via restore + publish.
        $entry = $this->publishedPage(self::BODY_ONE);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{code()}']], 'code draft');
        $codeRef = $state->versions[0]->readable;

        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->restore($entry->fresh(), $codeRef);
    }

    public function test_named_publish_republishes_a_legacy_page_against_the_published_body(): void
    {
        $entry = $this->legacyPublishedPage();
        $publication = app(EntryPublication::class);
        $pin = $publication->state($entry)->publishedVersion;
        $draft = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $this->bindSplitTenancy();

        $result = $publication->publishVersion($entry->fresh(), $pin);

        $this->assertSame($pin, $result->publishedVersion);
        $this->assertSame($draft->headVersion, $result->headVersion);
        $this->assertSame(self::BODY_TWO, $entry->fresh()->particle->payload);
    }

    public function test_restore_stores_illegal_legacy_nodes_but_named_publish_refuses_them(): void
    {
        $entry = $this->legacyPublishedPage();
        $publication = app(EntryPublication::class);
        $legacy = $publication->state($entry)->publishedVersion;
        $legacyBody = $entry->fresh()->particle->payload;
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $current = $publication->publish($entry->fresh());
        $this->bindSplitTenancy();

        $restored = $publication->restore($entry->fresh(), $legacy);

        $this->assertSame($legacyBody, $entry->fresh()->particle->payload);
        $this->assertSame($current->publishedVersion, $restored->publishedVersion);
        $this->assertNotEmpty($restored->diagnostics);
        $before = $entry->fresh()->getAttributes();
        try {
            $publication->publishVersion($entry->fresh(), $restored->headVersion);
            $this->fail('Restored illegal draft bypassed publication admission.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('marquee', implode('\n', $exception->errors()['body']));
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    public function test_programmatic_publish_cannot_pin_a_restored_illegal_working_head(): void
    {
        $entry = $this->legacyPublishedPage();
        $publication = app(EntryPublication::class);
        $legacy = $publication->state($entry)->publishedVersion;
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $publication->publish($entry->fresh());
        $this->bindSplitTenancy();
        $publication->restore($entry->fresh(), $legacy);
        $before = $entry->fresh()->getAttributes();

        try {
            $publication->publishAuthoredBody($entry->fresh());
            $this->fail('An illegal working head was compared to itself and published.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('marquee', implode('\n', $exception->errors()['body']));
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    private function legacyPublishedPage(): BeamUxEntry
    {
        $entry = BeamUxEntry::create(['slug' => 'legacy-published', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new BeamParticle, [[
            'kind' => 'block', 'id' => 'legacy', 'name' => 'marquee', 'isComponent' => false,
            'dynamic' => false, 'props' => [], 'children' => [],
        ]]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        app(EntryPublication::class)->baseline($entry);

        return $entry->fresh();
    }

    public function test_publishing_a_historical_code_version_refuses_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $state = $publication->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{code()}']], 'old code');
        $before = $entry->fresh()->getAttributes();
        $this->bindSplitTenancy();

        try {
            $publication->publishVersion($entry->fresh(), $state->headVersion);
            $this->fail('A historical code version was published without admission.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame($state->publishedVersion, $publication->state($entry->fresh())->publishedVersion);
    }

    /** An entry with a written body and NO version history — every entry authored before this path. */
    private function pageWithUnversionedBody(array $body): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => 'home-'.substr(bin2hex(random_bytes(4)), 0, 8),
            'type' => UxType::Page->value,
            'format' => 'tsx',
            'realm' => 'site',
        ]);

        app(EntryPublication::class)->write($entry, $body);

        // Compiled the way the console backfill would: at whatever address the unpinned entry has.
        app(CompileEntryBody::class)->forEntry($entry->refresh(), force: true);

        return $entry->fresh();
    }

    private function saveRequest(array $body): Request
    {
        return Request::create('/beam-ux-entries/x/save-body', 'POST', ['body' => $body]);
    }

    private function versionRows(BeamUxEntry $entry): int
    {
        return (int) \DB::table(Beam::table('versions'))
            ->where('versionable_id', (string) $entry->fresh()->particle_id)
            ->count();
    }

    private function createTables(?string $connection = null): void
    {
        Schema::connection($connection)->create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->integer('nav_order')->nullable();
            foreach (['nav_group', 'layout', 'template', 'seo_description', 'seo_social_image', 'seo_page_type', 'seo_published_date'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::connection($connection)->create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::connection($connection)->create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });
    }
}

/** A compiler with no toolchain — it echoes the source back as a module. */
class PublicationFakeCompiler implements EntryBodyCompiler
{
    public static bool $fails = false;

    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, ['mdx', 'tsx'], true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        if (self::$fails) {
            throw CompilationFailed::for($entry, 'the fake compiler was told to fail.');
        }

        return '/* compiled */ export default () => '.json_encode($source);
    }
}

/** A host's BeamUxEntry policy that allows editing and denies publishing. */
class DenyPublishEntryPolicy
{
    public function update($user, BeamUxEntry $entry): bool
    {
        return true;
    }

    public function publish($user, BeamUxEntry $entry): bool
    {
        return false;
    }
}

/** A host's BeamUxEntry policy that declares only `update`, and denies it. */
class DenyUpdateEntryPolicy
{
    public function update($user, BeamUxEntry $entry): bool
    {
        return false;
    }
}

/** A host's BeamUxEntry policy that allows publishing and denies editing. */
class AllowPublishDenyUpdateEntryPolicy
{
    public function update($user, BeamUxEntry $entry): bool
    {
        return false;
    }

    public function publish($user, BeamUxEntry $entry): bool
    {
        return true;
    }
}

/** A host's BeamUxEntry policy whose `update` a test can flip; `publish` is always allowed. */
class ToggleUpdateEntryPolicy
{
    public static bool $update = true;

    public function update($user, BeamUxEntry $entry): bool
    {
        return self::$update;
    }

    public function publish($user, BeamUxEntry $entry): bool
    {
        return true;
    }
}
