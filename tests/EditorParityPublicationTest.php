<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Data\ReleaseData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryDraftSaveOp;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Type\UxType;

/** EP-06/09/10/15: real publication/version/Release services, isolated SQLite and artifact disk.
 * The echo compiler isolates admission and artifact addressing; this is not Node/compiler proof.
 */
class EditorParityPublicationTest extends TestCase
{
    private const BODY_ONE = [['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'One']]]];

    private const BODY_TWO = [['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'Two']]]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['beam.ux.recording.git' => PHP_BINARY.'-ep-tests-no-git']);
        $this->createTables();
        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new EditorParityEchoCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    public function test_save_draft_preserves_untouched_illegal_nodes_but_checks_added_and_changed_nodes(): void
    {
        $entry = $this->legacyPublishedPage();
        $pub = app(EntryPublication::class);
        $body = $entry->particle->payload;
        $legal = self::BODY_ONE[0];
        $legal['id'] = 'new-heading';
        $body[] = $legal;
        $base = $pub->state($entry)->headVersion;
        $saved = EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', [
            'body' => $body, 'baseVersion' => $base,
        ]), null);
        $this->assertSame($body, $entry->fresh()->particle->payload);
        $this->assertNotSame($base, $saved->headVersion);
        $this->assertSame($base, $entry->fresh()->published_version);

        foreach (['changed' => 'legacy', 'added' => 'new-illegal'] as $case => $id) {
            $bad = $body;
            if ($case === 'changed') {
                $bad[0]['props'] = [['name' => 'title', 'kind' => 'string', 'value' => 'edited illegal node']];
            } else {
                $bad[] = array_replace($body[0], ['id' => $id]);
            }
            $before = $entry->fresh()->particle->getAttributes();
            $versions = $entry->particle->versions()->count();
            try {
                EntryDraftSaveOp::handle($entry->fresh(), Request::create('/', 'POST', [
                    'body' => $bad, 'baseVersion' => $saved->headVersion,
                ]), null);
                $this->fail('Saved '.$case.' illegal node.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString($id, json_encode($error->errors()));
                $this->assertArrayHasKey('body', $error->errors());
                $this->assertStringContainsString('at ['.($case === 'changed' ? '0' : '2').']', json_encode($error->errors()));
            }
            $this->assertSame($before, $entry->fresh()->particle->getAttributes());
            $this->assertSame($versions, $entry->fresh()->particle->versions()->count());
            $this->assertSame($saved->headVersion, $pub->state($entry->fresh())->headVersion);
        }
    }

    public static function publicationDates(): array
    {
        return ['default first date' => [null, '2026-01-02'], 'explicit date' => ['2025-12-24', '2025-12-24']];
    }

    #[DataProvider('publicationDates')]
    public function test_every_page_setting_is_versioned_then_copied_only_on_publish(?string $date, string $expectedDate): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-01-02 12:00:00'));
        $parent = $this->publishedPage(self::BODY_ONE);
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $pub->baseline($entry);
        $before = $entry->fresh()->getAttributes();
        $settings = new EntryPageSettingsData(title: 'All settings', parentId: $parent->id, segment: 'all-settings',
            navOrder: 17, navGroup: 'learn', layout: 'article-layout', template: 'article-template',
            description: 'Search description', socialImage: '/images/social.png', pageType: 'Article', publishedDate: $date);
        $draft = EntryDraftSaveOp::handle($entry->fresh(), Request::create('/', 'POST', [
            'body' => self::BODY_TWO, 'baseVersion' => $pub->state($entry->fresh())->headVersion,
            'pageSettings' => $settings->toArray(),
        ]), null);
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $version = app(VersionStore::class)->version($entry->fresh()->particle, $draft->headVersion);
        $this->assertSame(self::BODY_TWO, $version->snapshot['payload']);
        $this->assertEquals($settings->toArray(), $version->snapshot['meta']['pageSettings']);
        $pub->publishVersion($entry->fresh(), $draft->headVersion);
        // A null File Language leaves the row's (ADR-0233 d5), and the row reports the one it serves.
        $expected = array_replace($settings->toArray(), ['publishedDate' => $expectedDate, 'fileLanguage' => 'tsx']);
        $this->assertEquals($expected, EntryPageSettingsData::fromEntry($entry->fresh())->toArray());
        $this->assertSame('2026-01-02', substr($entry->fresh()->first_published_at, 0, 10));
        $firstDate = $entry->fresh()->first_published_at;
        $this->travel(2)->days();
        $next = $pub->recordDraft($entry->fresh(), self::BODY_ONE, pageSettings: new EntryPageSettingsData(title: 'Next'));
        $pub->publishVersion($entry->fresh(), $next->headVersion);
        $this->assertSame($firstDate, $entry->fresh()->first_published_at);
        $this->assertSame('2026-01-02', $entry->fresh()->seo_published_date);
    }

    public function test_release_collision_refuses_without_moving_any_member(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $pub = app(EntryPublication::class);
        $release = $service->create($one, 'Colliding URLs', $author);
        foreach ([$one, $two] as $entry) {
            $draft = $pub->recordDraft($entry, self::BODY_TWO, pageSettings: new EntryPageSettingsData(title: 'Collision', segment: 'same-url'));
            $service->putMember($release, $entry->id, $draft->headVersion, $author);
        }
        $this->review($service, $release, $author, $reviewer);
        $before = [$one->fresh()->getAttributes(), $two->fresh()->getAttributes()];
        try {
            $service->transition($release, 'publish', $reviewer);
            $this->fail('Published colliding Release URLs.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->assertSame($before, [$one->fresh()->getAttributes(), $two->fresh()->getAttributes()]);
        $this->assertSame('approved', $release->fresh()->status);
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($one->fresh()));
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($two->fresh()));
    }

    public function test_single_named_publish_refuses_a_never_published_parent(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $parent = $this->pageWithUnversionedBody(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $draft = $pub->recordDraft($entry, self::BODY_TWO, pageSettings: new EntryPageSettingsData(title: 'Child', parentId: $parent->id, segment: 'child'));
        $before = $entry->fresh()->getAttributes();
        try {
            $pub->publishVersion($entry->fresh(), $draft->headVersion);
            $this->fail('Published below an unpublished parent.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_joining_a_release_recompiles_the_pruned_selected_version_not_the_head(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture();
        $pub = app(EntryPublication::class);
        $selected = $pub->recordDraft($entry, self::BODY_TWO);
        $path = $this->artifacts()->path($entry, $this->artifacts()->versionKey($selected->headVersion));
        $latest = $pub->recordDraft($entry->fresh(), self::BODY_ONE);
        $this->assertFalse($this->artifacts()->disk()->exists($path));
        $release = $service->create($entry, 'Restore artifact', $author);
        $service->putMember($release, $entry->id, $selected->headVersion, $author);
        $this->assertStringContainsString('Two', $this->artifacts()->disk()->get($path));
        $this->assertSame($selected->headVersion, $release->members()->firstOrFail()->version_id);
        $this->assertSame($latest->headVersion, $pub->state($entry->fresh())->headVersion);
        $this->assertSame($selected->publishedVersion, $entry->fresh()->published_version);
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public static function staleResolutions(): array
    {
        return ['rebase' => ['rebase'], 'drop' => ['drop']];
    }

    #[DataProvider('staleResolutions')]
    public function test_moved_pin_is_projected_and_blocks_publish_until_explicit_resolution(string $resolution): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $pub = app(EntryPublication::class);
        $release = $service->create($one, 'Moved pin', $author);
        $selected = $pub->recordDraft($one, self::BODY_TWO);
        $other = $pub->recordDraft($two, self::BODY_TWO);
        $service->putMember($release, $one->id, $selected->headVersion, $author);
        $service->putMember($release, $two->id, $other->headVersion, $author);
        $this->review($service, $release, $author, $reviewer);
        $moved = $pub->recordDraft($one->fresh(), self::BODY_ONE);
        $pub->publishVersion($one->fresh(), $moved->headVersion);
        $member = collect(ReleaseData::project($release->fresh())->members)->firstWhere('entryId', $one->id);
        $this->assertTrue($member->stale);
        $before = [$one->fresh()->published_version, $two->fresh()->published_version];
        try {
            $service->transition($release, 'publish', $reviewer);
            $this->fail('Published a stale Release.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->assertSame($before, [$one->fresh()->published_version, $two->fresh()->published_version]);
        if ($resolution === 'rebase') {
            $service->rebase($release, $one->id, $author);
            $member = $release->members()->where('entry_id', $one->id)->firstOrFail();
            $this->assertSame($selected->headVersion, $member->version_id);
            $this->assertSame($moved->headVersion, $member->observed_pin);
        } else {
            $service->dropMember($release, $one->id, $author);
            $this->assertFalse($release->members()->where('entry_id', $one->id)->exists());
        }
        $this->assertSame('draft', $release->fresh()->status);
        $this->assertNull($release->fresh()->reviewed_by);
        foreach (ReleaseData::project($release->fresh())->members as $member) {
            $this->assertFalse($member->stale);
        }
        $this->review($service, $release, $author, $reviewer);
        $service->transition($release, 'publish', $reviewer);
        $this->assertSame('published', $release->fresh()->status);
        $this->assertSame($resolution === 'rebase' ? $selected->headVersion : $moved->headVersion, $one->fresh()->published_version);
        $this->assertSame($other->headVersion, $two->fresh()->published_version);
    }

    public function test_rollback_itself_is_reviewed_and_publishes_the_previous_version(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $oldPin = $entry->published_version;
        [$service, $author, $reviewer] = $this->releaseFixture();
        $pub = app(EntryPublication::class);
        $selected = $pub->recordDraft($entry, self::BODY_TWO);
        $release = $service->create($entry, 'New body', $author);
        $service->putMember($release, $entry->id, $selected->headVersion, $author);
        $this->review($service, $release, $author, $reviewer);
        $service->transition($release, 'publish', $reviewer);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $rollback = $service->rollback($release, $author);
        $this->assertSame($release->id, $rollback->rollback_of);
        $this->assertSame($oldPin, $rollback->members()->firstOrFail()->version_id);
        $this->assertSame($selected->headVersion, $entry->fresh()->published_version);
        try {
            $service->transition($rollback, 'publish', $author);
            $this->fail('Published an unreviewed rollback.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->review($service, $rollback, $author, $reviewer);
        $service->transition($rollback, 'publish', $reviewer);
        $this->assertSame('published', $rollback->fresh()->status);
        $this->assertSame($oldPin, $entry->fresh()->published_version);
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame($selected->headVersion, $pub->state($entry->fresh())->headVersion);
    }

    private function review(ReleaseService $service, $release, $author, $reviewer): void
    {
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
    }

    private function releaseFixture(): array
    {
        config([
            'beam.ux.releases.review_required' => true,
            'beam.ux.releases.edit_ability' => 'update',
            'beam.ux.releases.publish_ability' => 'publish',
        ]);
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table'] as $stub) {
            (require __DIR__."/../database/migrations/shared/{$stub}.php.stub")->up();
        }
        $author = (new \Illuminate\Foundation\Auth\User)->setKeyType('string')->forceFill(['id' => 'author']);
        $reviewer = (new \Illuminate\Foundation\Auth\User)->setKeyType('string')->forceFill(['id' => 'reviewer']);
        \Illuminate\Support\Facades\Gate::before(fn () => true);
        $provider = \Mockery::mock(\Illuminate\Contracts\Auth\UserProvider::class);
        $provider->shouldReceive('retrieveById')->andReturnUsing(fn ($id) => match ($id) {
            'author' => $author, 'reviewer' => $reviewer, default => null,
        });
        $guard = \Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
        $guard->shouldReceive('user')->andReturn(null);
        $guard->shouldReceive('id')->andReturn(null);
        \Illuminate\Support\Facades\Auth::shouldReceive('createUserProvider')->andReturn($provider);
        \Illuminate\Support\Facades\Auth::shouldReceive('guard')->andReturn($guard);

        return [app(ReleaseService::class), $author, $reviewer];
    }

    private function artifacts(): EntryArtifactStore
    {
        return app(EntryArtifactStore::class);
    }

    private function publishedPage(array $body): BeamUxEntry
    {
        $entry = $this->pageWithUnversionedBody($body);

        app(EntryPublication::class)->publish($entry->fresh());

        return $entry->fresh();
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

/** An artifact-address test double, not a substitute for the suite's real compiler tests. */
class EditorParityEchoCompiler implements EntryBodyCompiler
{
    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, ['mdx', 'tsx'], true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        return '/* compiled */ export default () => '.json_encode($source);
    }
}
