<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Ux\Data\ReleaseConfigChangeData;
use Splicewire\Beam\Ux\Data\ReleaseConfigKeyChangeData;
use Splicewire\Beam\Ux\Data\ReleaseData;
use Splicewire\Beam\Ux\Data\ReleaseMemberData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseChanges;
use Splicewire\Beam\Ux\Release\ReleaseConfigChanges;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseConfigSection;

/**
 * config-sections ticket 11: a reviewer opening a Release that holds config sees each config member beside its entries.
 * - The Release projection carries every member's KIND and LABEL; a config member is labelled by its section's title
 *   and has no entry id.
 * - The changes list carries a SEPARATE config list (section, package, version move, reloads workers, key diff), kept
 *   out of the page-reach map.
 * - A secret never carries a value: it reads `replaced`, `removed` or `unchanged`.
 * - A reviewer drops a config member as they drop an entry, and the Release revises.
 *
 * Same database wiring as ConfigMemberHeadDraftTest: `central` shares the default connection's database.
 */
class ReleaseConfigMembersTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    private const FIXTURES = __DIR__.'/Fixtures/Config';

    private const ROOT = 'release-fixture';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $db = 'file:beam11_'.uniqid().'?mode=memory&cache=shared';
        $shared = ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.connections.testing', $shared);
        $app['config']->set('database.connections.central', $shared);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('beam.ux.releases.allow_self_approval', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'create_beam_ux_release_recordings_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require dirname(__DIR__)."/vendor/splicewire/laravel-beam/database/migrations/shared/{$stub}.php.stub")->up();
        }

        Gate::before(fn () => true);
        Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author']);
        $this->author = User::query()->findOrFail(7);
        $this->actingAs($this->author);

        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(\Splicewire\Beam\Config\Overrides\ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(ReleaseConfigSection::class, owner: 'qa/fixture');
    }

    private function page(): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => 'about-'.Str::random(4), 'title' => 'About', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'about-'.Str::random(4)]);
    }

    /** @param  list<array{pointer: string, op: string, value?: mixed}>  $ops */
    private function saveDraft(array $ops): void
    {
        app(ConfigOverrideStore::class)->saveDraft(self::ROOT, $ops);
    }

    private function releaseWithConfig(): BeamUxRelease
    {
        $release = app(ReleaseService::class)->create($this->page(), 'mixed', $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);

        return $release->fresh();
    }

    private function configMember(BeamUxRelease $release): ?BeamUxReleaseMember
    {
        return $release->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)->where('member_ref', self::ROOT)->first();
    }

    /** @return array<string, ReleaseConfigKeyChangeData> */
    private function keysOf(ReleaseConfigChangeData $change): array
    {
        return collect($change->keys)->keyBy('pointer')->all();
    }

    public function test_the_release_projection_lists_a_config_member_with_its_kind_and_label_after_the_entries(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $page = $this->page();
        $version = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $release = app(ReleaseService::class)->create($page->fresh(), 'mixed', $this->author);
        app(ReleaseService::class)->putMember($release, (string) $page->id, $version, $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);
        $release = $release->fresh();

        $members = ReleaseData::project($release)->members;

        $this->assertCount(2, $members);
        [$entry, $config] = $members;
        $this->assertInstanceOf(ReleaseMemberData::class, $config);
        $this->assertSame(['entry', 'About'], [$entry->kind, $entry->label]);
        $this->assertSame($entry->entryId, $entry->ref, 'an entry member is addressed by its entry id');
        $this->assertSame([null, 'config-override', self::ROOT, 'Release Fixture'], [$config->entryId, $config->kind, $config->ref, $config->label]);
        $this->assertSame($this->configMember($release)->version_id, $config->versionId);
        $this->assertNull($config->observedPin, 'the section had nothing published when it joined');
        $this->assertFalse($config->stale);
    }

    public function test_a_config_member_is_stale_when_the_published_pin_moves_after_it_joined(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $release = $this->releaseWithConfig();

        app(ConfigOverrideStore::class)->publish(self::ROOT); // published outside this Release

        $config = collect(ReleaseData::project($release->fresh())->members)->firstWhere('kind', 'config-override');
        $this->assertTrue($config->stale);
    }

    public function test_the_config_list_carries_the_key_diff_with_secrets_as_replaced(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello'], ['pointer' => '/token', 'op' => 'set', 'value' => 'fake-secret-one']]);
        $release = $this->releaseWithConfig();

        [$change] = app(ReleaseConfigChanges::class)->of($release);

        $this->assertSame([self::ROOT, 'Release Fixture', 'qa/fixture', null, 1, false],
            [$change->root, $change->title, $change->package, $change->fromVersion, $change->toVersion, $change->reloadsWorkers]);
        $keys = $this->keysOf($change);
        $this->assertSame(['/heading', '/token'], array_keys($keys));

        $heading = $keys['/heading'];
        $this->assertSame([ConfigOverrideOp::Set, null, false, 'Hello', 'operator', ConfigEffect::Live, false, null],
            [$heading->op, $heading->published, $heading->publishedSet, $heading->after, $heading->setBy, $heading->applies, $heading->secret, $heading->secretChange]);

        $token = $keys['/token'];
        $this->assertSame([true, 'replaced', null, null], [$token->secret, $token->secretChange, $token->published, $token->after]);
        $this->assertStringNotContainsString('fake-secret-one', json_encode($change->toArray()), 'a secret value never reaches the projection');

        // An operator (central) Release previews the one file its commit writes: the member's version, secrets valueless.
        $this->assertSame('config/overrides/release-fixture.json', $change->mirrorPath);
        $mirror = json_decode((string) $change->mirror, true);
        $this->assertSame([self::ROOT, 1], [$mirror['root'], $mirror['version']]);
        $this->assertSame([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello'], ['pointer' => '/token', 'op' => 'set', 'secret' => true]],
            $mirror['overrides']);
        $this->assertNull(app(ConfigOverrideStore::class)->scope(self::ROOT)->published_version, 'the preview published nothing');
    }

    public function test_the_config_list_diffs_against_the_published_version_and_skips_unchanged_keys(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'One'], ['pointer' => '/token', 'op' => 'set', 'value' => 'fake-secret-one'],
            ['pointer' => '/banner', 'op' => 'set', 'value' => 'Stays']]);
        app(ConfigOverrideStore::class)->publish(self::ROOT); // v1 published
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Two'], ['pointer' => '/token', 'op' => 'unset']]);
        $release = $this->releaseWithConfig();

        [$change] = app(ReleaseConfigChanges::class)->of($release);

        $this->assertSame([1, 2], [$change->fromVersion, $change->toVersion]);
        $keys = $this->keysOf($change);
        $this->assertSame(['/heading', '/token'], array_keys($keys), 'the unchanged banner is not listed');
        $this->assertSame(['One', true, 'Two', 'operator'], [$keys['/heading']->published, $keys['/heading']->publishedSet, $keys['/heading']->after, $keys['/heading']->setBy]);
        $this->assertSame([null, 'inherited', 'removed'], [$keys['/token']->op, $keys['/token']->setBy, $keys['/token']->secretChange]);
    }

    public function test_the_config_list_stays_out_of_the_page_reach_map(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $release = $this->releaseWithConfig();

        $changes = app(ReleaseChanges::class)->of($release);

        $this->assertCount(1, $changes->config);
        $this->assertSame(self::ROOT, $changes->config[0]->root);
        $this->assertNotContains(self::ROOT, collect($changes->reach)->pluck('entryId')->all(), 'a config section is no reach member');
        $this->assertNotContains(self::ROOT, collect($changes->reach)->pluck('name')->all());
        foreach ($changes->pages as $page) {
            $this->assertStringNotContainsString(self::ROOT, json_encode($page->toArray()), 'no page comes from the config member');
        }
    }

    public function test_no_secret_value_reaches_any_wire_the_panel_reads_before_or_after_a_drop(): void
    {
        $this->saveDraft([['pointer' => '/token', 'op' => 'set', 'value' => 'fake-secret-two'], ['pointer' => '/heading', 'op' => 'set', 'value' => 'Hi']]);
        $release = $this->releaseWithConfig();
        $wires = fn (BeamUxRelease $r): string => json_encode([ReleaseData::project($r)->toArray(), app(ReleaseChanges::class)->of($r)->toArray()]);

        $before = $wires($release);
        $this->assertStringContainsString('replaced', $before);
        $this->assertStringNotContainsString('fake-secret-two', $before);
        [$change] = app(ReleaseConfigChanges::class)->of($release);
        $token = $this->keysOf($change)['/token'];
        // setBy and applies are a tier and an effect, never a value
        $this->assertSame(['operator', ConfigEffect::Live], [$token->setBy, $token->applies]);

        app(ReleaseService::class)->dropMemberByKind($release->fresh(), BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);
        $this->assertStringNotContainsString('fake-secret-two', $wires($release->fresh()));
    }

    public function test_a_reviewer_drops_a_config_member_and_the_release_revises(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $release = $this->releaseWithConfig();
        app(ReleaseService::class)->transition($release->fresh(), 'submit', $this->author);
        $this->assertSame('in_review', $release->fresh()->status);

        app(ReleaseService::class)->dropMemberByKind($release->fresh(), BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);

        $this->assertNull($this->configMember($release->fresh()));
        $this->assertSame('draft', $release->fresh()->status, 'dropping a member revises the Release, as for an entry');
        $this->assertSame([], app(ReleaseConfigChanges::class)->of($release->fresh()));
    }
}
