<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Data\ReleaseConfigChangeData;
use Splicewire\Beam\Ux\Data\ReleaseConfigKeyChangeData;
use Splicewire\Beam\Ux\Data\ReleaseData;
use Splicewire\Beam\Ux\Data\ReleaseMemberData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Particle\ReleaseCommandOp;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseChanges;
use Splicewire\Beam\Ux\Release\ReleaseConfigChanges;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseConfigSection;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseKeysSection;
use Splicewire\Beam\Ux\Type\ReleaseConfigSecretChange;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * config-sections ticket 11: a reviewer opening a Release that holds config sees each config member beside its entries.
 * - The Release projection carries every member's KIND and LABEL; a config member is labelled by its section's title
 *   and has no entry id.
 * - The changes list carries a SEPARATE config list (section, package, version move, reloads workers, key diff), kept
 *   out of the page-reach map. Published is the key's EFFECTIVE value through the ladder; After is the new value, or
 *   for a dropped override the value it falls back to, and Set by is that tier (as prototyped, ticket 01).
 * - Values are `beam-config`'s: a reader its read ability refuses gets `readable: false`, no keys and no mirror.
 * - A secret never carries a value, including a secret leaf inside an object: it reads replaced, removed or unchanged.
 * - The mirror file and preview appear only where the recording really writes them (central, local mirror disk).
 * - A reviewer drops a config member as they drop an entry, through the command path, and the Release revises.
 *
 * Same database wiring as ConfigRecordingTest: `central` shares the default connection's database, and a SEPARATE
 * database stands in for a tenant's own store.
 */
class ReleaseConfigMembersTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    /** Whether the Gate lets the author read `beam-config` (ConfigSectionAccess::READ). */
    private bool $configReadable = true;

    private const FIXTURES = __DIR__.'/Fixtures/Config';

    private const ROOT = 'release-fixture';

    private const KEYS = 'release-keys';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $db = 'file:beam11_'.uniqid().'?mode=memory&cache=shared';
        $shared = ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.connections.testing', $shared);
        $app['config']->set('database.connections.central', $shared);
        $tdb = 'file:beam11t_'.uniqid().'?mode=memory&cache=shared';
        $app['config']->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $tdb, 'prefix' => '', 'foreign_key_constraints' => false]);
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

        // Every ability passes except `beam-config`'s read, which each test sets (the realm-aware answer is core's Gate).
        Gate::before(fn ($user, string $ability) => $ability === ConfigSectionAccess::READ ? $this->configReadable : true);
        Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author']);
        $this->author = User::query()->findOrFail(7);
        $this->actingAs($this->author);

        // A booted host: each section's config() holds its host file's values (the reader's observed truth).
        config([self::ROOT => require self::FIXTURES.'/host/'.self::ROOT.'.php', self::KEYS => require self::FIXTURES.'/host/'.self::KEYS.'.php']);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(\Splicewire\Beam\Config\Overrides\ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(ReleaseConfigSection::class, owner: 'qa/fixture');
        app(ConfigSectionRegistry::class)->section(ReleaseKeysSection::class, owner: 'qa/keys');
    }

    private function page(): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => 'about-'.Str::random(4), 'title' => 'About', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'about-'.Str::random(4)]);
    }

    /** @param  list<array<string, mixed>>  $ops */
    private function saveDraft(array $ops, string $root = self::ROOT): void
    {
        app(ConfigOverrideStore::class)->saveDraft($root, $ops);
    }

    private function releaseWithConfig(string $root = self::ROOT): BeamUxRelease
    {
        $release = app(ReleaseService::class)->create($this->page(), 'mixed', $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, $root, $this->author);

        return $release->fresh();
    }

    private function configMember(BeamUxRelease $release, string $root = self::ROOT): ?BeamUxReleaseMember
    {
        return $release->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)->where('member_ref', $root)->first();
    }

    private function change(BeamUxRelease $release, string $root = self::ROOT): ReleaseConfigChangeData
    {
        return collect(app(ReleaseConfigChanges::class)->of($release))->firstWhere('root', $root);
    }

    /** @return array<string, ReleaseConfigKeyChangeData> */
    private function keysOf(ReleaseConfigChangeData $change): array
    {
        return collect($change->keys)->keyBy('pointer')->all();
    }

    /** A local mirror disk, so the recording would write `config/overrides/<root>.json` (no git needed to preview it). */
    private function withLocalMirrorDisk(): void
    {
        $dir = sys_get_temp_dir().'/beam11-mirror-'.bin2hex(random_bytes(4));
        config(['filesystems.disks.beam11-mirror' => ['driver' => 'local', 'root' => $dir], 'beam.ux.storage.mirror_disk' => 'beam11-mirror']);
        Storage::forgetDisk('beam11-mirror');
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

    public function test_a_mixed_release_carries_each_members_type_for_the_page_theme_config_grouping(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $page = $this->page();
        $version = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $release = app(ReleaseService::class)->create($page->fresh(), 'page theme config', $this->author);
        app(ReleaseService::class)->putMember($release, (string) $page->id, $version, $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);
        // A theme member as the projection reads it (an entry member row); its saved version is not what this test is about.
        $theme = BeamUxEntry::create(['slug' => 'brand-theme', 'title' => 'Brand', 'type' => 'theme', 'format' => 'css', 'realm' => 'site', 'namespace' => 'theme']);
        (new BeamUxReleaseMember)->forceFill(['release_id' => $release->id, 'entry_id' => $theme->id, 'member_kind' => BeamUxReleaseMember::KIND_ENTRY,
            'member_ref' => $theme->id, 'version_id' => 'theme-v2', 'observed_pin' => null])->save();

        $members = collect(ReleaseData::project($release->fresh())->members);

        $this->assertCount(3, $members);
        $this->assertSame([UxType::Page, 'About'], [$members->firstWhere('entryId', (string) $page->id)->entryType, $members->firstWhere('entryId', (string) $page->id)->label]);
        $this->assertSame([UxType::Theme, 'Brand'], [$members->firstWhere('entryId', (string) $theme->id)->entryType, $members->firstWhere('entryId', (string) $theme->id)->label]);
        $config = $members->firstWhere('kind', 'config-override');
        $this->assertSame([null, null], [$config->entryType, $config->entryId]);
        $this->assertSame(['page', 'theme', null], [$members[0]->toArray()['entryType'], $members->firstWhere('entryId', (string) $theme->id)->toArray()['entryType'], $config->toArray()['entryType']],
            'the wire carries the type as its string');
    }

    public function test_a_config_member_is_stale_when_the_published_pin_moves_after_it_joined(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $release = $this->releaseWithConfig();

        app(ConfigOverrideStore::class)->publish(self::ROOT); // published outside this Release

        $config = collect(ReleaseData::project($release->fresh())->members)->firstWhere('kind', 'config-override');
        $this->assertTrue($config->stale);
    }

    public function test_published_is_the_effective_value_and_secrets_read_replaced_with_the_mirror_previewed(): void
    {
        $this->withLocalMirrorDisk();
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello'], ['pointer' => '/token', 'op' => 'set', 'value' => 'fake-secret-one']]);
        $release = $this->releaseWithConfig();

        $change = $this->change($release);

        $this->assertSame([self::ROOT, 'Release Fixture', 'qa/fixture', null, 1, false, true],
            [$change->root, $change->title, $change->package, $change->fromVersion, $change->toVersion, $change->reloadsWorkers, $change->readable]);
        $keys = $this->keysOf($change);
        $this->assertSame(['/heading', '/token'], array_keys($keys));

        // Nothing was published on this tier, so Published is the host's value, through the ladder.
        $heading = $keys['/heading'];
        $this->assertSame([ConfigOverrideOp::Set, 'default heading', false, 'Hello', ConfigTier::Operator, ConfigEffect::Live, false, null],
            [$heading->op, $heading->published, $heading->publishedSet, $heading->after, $heading->setBy, $heading->applies, $heading->secret, $heading->secretChange]);

        $token = $keys['/token'];
        $this->assertSame([true, ReleaseConfigSecretChange::Replaced, null, null], [$token->secret, $token->secretChange, $token->published, $token->after]);
        $this->assertStringNotContainsString('fake-secret-one', json_encode($change->toArray()), 'a secret value never reaches the projection');

        // An operator Release on a host that records files previews the one file its commit writes; secrets valueless.
        $this->assertSame('config/overrides/release-fixture.json', $change->mirrorPath);
        $mirror = json_decode((string) $change->mirror, true);
        $this->assertSame([self::ROOT, 1], [$mirror['root'], $mirror['version']]);
        $this->assertSame([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello'], ['pointer' => '/token', 'op' => 'set', 'secret' => true]],
            $mirror['overrides']);
        $this->assertNull(app(ConfigOverrideStore::class)->scope(self::ROOT)->published_version, 'the preview published nothing');
    }

    public function test_no_mirror_is_promised_on_a_host_that_records_no_files(): void
    {
        config(['beam.ux.storage.mirror_disk' => null]);
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);

        $change = $this->change($this->releaseWithConfig());

        $this->assertSame([null, null, true], [$change->mirrorPath, $change->mirror, $change->readable]);
        $this->assertCount(1, $change->keys, 'the key diff is still there');
    }

    public function test_a_reader_beam_config_refuses_gets_no_values_and_no_mirror(): void
    {
        $this->withLocalMirrorDisk();
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $release = $this->releaseWithConfig();
        $this->configReadable = false;

        $change = $this->change($release);

        $this->assertSame([false, [], false, null], [$change->readable, $change->keys, $change->reloadsWorkers, $change->mirror]);
        $this->assertSame([self::ROOT, 'qa/fixture', null, 1], [$change->root, $change->package, $change->fromVersion, $change->toVersion]);
        $this->assertStringNotContainsString('Hello', json_encode(app(ReleaseChanges::class)->of($release)->toArray()));
    }

    public function test_a_dropped_override_falls_back_to_the_tier_below_and_unchanged_keys_are_skipped(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'One'], ['pointer' => '/banner', 'op' => 'set', 'value' => 'Stays']]);
        app(ConfigOverrideStore::class)->publish(self::ROOT); // v1 published
        $this->saveDraft([['pointer' => '/heading', 'op' => 'unset']]);

        $change = $this->change($this->releaseWithConfig());

        $this->assertSame([1, 2], [$change->fromVersion, $change->toVersion]);
        $keys = $this->keysOf($change);
        $this->assertSame(['/heading'], array_keys($keys), 'the unchanged banner is not listed');
        $heading = $keys['/heading'];
        $this->assertSame([null, 'One', true, 'default heading', ConfigTier::Host],
            [$heading->op, $heading->published, $heading->publishedSet, $heading->after, $heading->setBy]);
    }

    public function test_a_secret_set_to_explicit_null_reads_removed(): void
    {
        $this->saveDraft([['pointer' => '/token', 'op' => 'set', 'value' => 'fake-secret-one']]);
        app(ConfigOverrideStore::class)->publish(self::ROOT);
        $this->saveDraft([['pointer' => '/token', 'op' => 'null']]);

        $token = $this->keysOf($this->change($this->releaseWithConfig()))['/token'];

        $this->assertSame([ConfigOverrideOp::Null, ReleaseConfigSecretChange::Removed, null, null, true],
            [$token->op, $token->secretChange, $token->published, $token->after, $token->publishedSet]);
    }

    public function test_a_rollback_member_removes_the_override_and_every_key_falls_back(): void
    {
        $this->withLocalMirrorDisk();
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'One']]);
        $published = app(ConfigOverrideStore::class)->publish(self::ROOT)->published_version;
        // What ReleaseService::rollback() places for a section first published by the Release it reverts: the current pin
        // observed, and a null version (the override is removed).
        $release = app(ReleaseService::class)->create($this->page(), 'rollback', $this->author);
        (new BeamUxReleaseMember)->forceFill(['release_id' => $release->id, 'member_kind' => BeamUxReleaseMember::KIND_CONFIG,
            'member_ref' => self::ROOT, 'version_id' => null, 'observed_pin' => $published])->save();

        $change = $this->change($release->fresh());

        $this->assertSame([1, null, 'config/overrides/release-fixture.json', null], [$change->fromVersion, $change->toVersion, $change->mirrorPath, $change->mirror],
            'the commit removes the file: a path with no content');
        $heading = $this->keysOf($change)['/heading'];
        $this->assertSame([null, 'One', 'default heading', ConfigTier::Host], [$heading->op, $heading->published, $heading->after, $heading->setBy]);
    }

    public function test_a_reload_key_says_it_reloads_workers_and_a_secret_inside_an_object_is_masked(): void
    {
        $this->saveDraft([
            ['pointer' => '/workers', 'op' => 'set', 'value' => 'four'],
            ['pointer' => '/mail', 'op' => 'set', 'value' => ['host' => 'smtp.new.test', 'password' => 'fake-object-secret']],
        ], self::KEYS);
        $release = $this->releaseWithConfig(self::KEYS);

        $change = $this->change($release, self::KEYS);

        $this->assertTrue($change->reloadsWorkers);
        $keys = $this->keysOf($change);
        $this->assertSame([ConfigEffect::Reload, 'two', 'four'], [$keys['/workers']->applies, $keys['/workers']->published, $keys['/workers']->after]);
        $this->assertSame('smtp.new.test', $keys['/mail']->after['host']);
        $this->assertNull($keys['/mail']->after['password'] ?? null, 'the secret leaf is masked inside the object');
        $this->assertSame(['host' => 'mail.host.test', 'password' => null], $keys['/mail']->published);
        $this->assertStringNotContainsString('fake-object-secret', json_encode([$change->toArray(), ReleaseData::project($release)->toArray()]));
    }

    public function test_dropping_a_secret_override_never_shows_the_host_secret_it_falls_back_to(): void
    {
        $this->withLocalMirrorDisk();
        $this->saveDraft([['pointer' => '/token', 'op' => 'set', 'value' => 'op-secret-y']]);
        app(ConfigOverrideStore::class)->publish(self::ROOT);
        $this->saveDraft([['pointer' => '/token', 'op' => 'unset']]);
        $release = $this->releaseWithConfig();

        $token = $this->keysOf($this->change($release))['/token'];

        $this->assertSame([null, ReleaseConfigSecretChange::Removed, null, null, ConfigTier::Host],
            [$token->op, $token->secretChange, $token->published, $token->after, $token->setBy], 'it falls back to the host tier, valueless');
        $wire = json_encode([ReleaseData::project($release)->toArray(), app(ReleaseChanges::class)->of($release)->toArray()]);
        foreach (['host-secret-x', 'op-secret-y'] as $secret) {
            $this->assertStringNotContainsString($secret, $wire);
        }
    }

    public function test_a_published_compound_drop_falls_back_to_the_hosts_object_with_its_secret_masked(): void
    {
        $this->saveDraft([['pointer' => '/mail', 'op' => 'set', 'value' => ['host' => 'smtp.op.test', 'password' => 'op-pw-y']]], self::KEYS);
        app(ConfigOverrideStore::class)->publish(self::KEYS); // v1: the operator overrides the whole /mail object
        $this->saveDraft([['pointer' => '/mail', 'op' => 'unset']], self::KEYS);
        $release = $this->releaseWithConfig(self::KEYS);
        // The drop publishes: the published layer no longer has /mail, so the reader resolves only its leaves.
        app(ConfigOverrideStore::class)->publish(self::KEYS);

        $mail = $this->keysOf($this->change($release->fresh(), self::KEYS))['/mail'];

        $this->assertSame([null, ['host' => 'mail.host.test', 'password' => null], ConfigTier::Host], [$mail->op, $mail->after, $mail->setBy],
            "the host's object is in force again, its secret leaf masked, and /mailer (a string prefix, not a pointer prefix) is not taken");
        $wire = json_encode([ReleaseData::project($release->fresh())->toArray(), app(ReleaseChanges::class)->of($release->fresh())->toArray()]);
        foreach (['host-pw-x', 'op-pw-y'] as $secret) {
            $this->assertStringNotContainsString($secret, $wire);
        }
    }

    public function test_changes_without_the_config_list_never_resolve_the_config_reader(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $release = $this->releaseWithConfig();
        // What the Preview bar asks for: the reader (file scans, schema build, decrypting layers) must not run at all.
        $this->app->bind(ConfigSectionReader::class, fn () => throw new \LogicException('the config reader was resolved'));

        $this->assertSame([], app(ReleaseChanges::class)->of($release, withConfig: false)->config);
    }

    public function test_a_tenant_release_shows_the_tenant_tier_with_no_file(): void
    {
        $this->withLocalMirrorDisk();
        $this->migrateOn('tenant');
        $this->enterTenantRealm();
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->saveDraft(self::ROOT, [['pointer' => '/banner', 'op' => 'set', 'value' => 'tenant banner']]);
        $release = $this->releaseWithConfig();

        $change = $this->change($release);

        $this->assertSame([null, null, true], [$change->mirrorPath, $change->mirror, $change->readable], 'database history only');
        $banner = $this->keysOf($change)['/banner'];
        $this->assertSame([ConfigOverrideOp::Set, 'tenant banner', ConfigTier::Tenant], [$banner->op, $banner->after, $banner->setBy]);

        // In the tenant realm too, a reader the read ability refuses gets no values.
        $this->configReadable = false;
        $refused = $this->change($release);
        $this->assertSame([false, []], [$refused->readable, $refused->keys]);
        $this->leaveTenantRealm();
    }

    public function test_no_secret_value_reaches_any_wire_the_panel_reads(): void
    {
        $this->withLocalMirrorDisk();
        $this->saveDraft([['pointer' => '/token', 'op' => 'set', 'value' => 'fake-secret-two'], ['pointer' => '/heading', 'op' => 'set', 'value' => 'Hi']]);
        $release = $this->releaseWithConfig();
        $wire = json_encode([ReleaseData::project($release)->toArray(), app(ReleaseChanges::class)->of($release)->toArray()]);

        $this->assertStringContainsString('"replaced"', $wire);
        $this->assertStringNotContainsString('fake-secret-two', $wire);
        $token = $this->keysOf($this->change($release))['/token'];
        $this->assertSame([ConfigTier::Operator, ConfigEffect::Live], [$token->setBy, $token->applies], 'a tier and an effect, never a value');
    }

    public function test_a_reviewer_drops_a_config_member_through_the_command_and_the_release_revises(): void
    {
        $this->saveDraft([['pointer' => '/heading', 'op' => 'set', 'value' => 'Hello']]);
        $release = $this->releaseWithConfig();
        app(ReleaseService::class)->transition($release->fresh(), 'submit', $this->author);
        $this->assertSame('in_review', $release->fresh()->status);

        $read = ReleaseCommandOp::handle($release->fresh(),
            Request::create('/', 'POST', ['action' => 'drop', 'kind' => BeamUxReleaseMember::KIND_CONFIG, 'ref' => self::ROOT]), $this->author);

        $this->assertNull($this->configMember($release->fresh()));
        $this->assertSame('draft', $release->fresh()->status, 'dropping a member revises the Release, as for an entry');
        $this->assertSame([], collect($read->members)->where('kind', 'config-override')->all());
        $this->assertSame([], app(ReleaseConfigChanges::class)->of($release->fresh()));
    }

    /** Enter a tenant hop: the default connection becomes the tenant's store and tenancy is active (as ConfigRecordingTest). */
    private function enterTenantRealm(string $connection = 'tenant'): void
    {
        $tenancy = \Mockery::mock(TenancyContext::class)->shouldIgnoreMissing();
        $tenancy->shouldReceive('isActive')->andReturnTrue();
        $tenancy->shouldReceive('isMultiTenant')->andReturnTrue();
        $tenancy->shouldReceive('currentTenantId')->andReturn('tenant-1');
        $tenancy->shouldReceive('centralConnection')->andReturn('central');
        $this->app->instance(TenancyContext::class, $tenancy);
        config(['database.default' => $connection]);
        $this->app->forgetInstance(ConfigOverrideStore::class);
    }

    private function leaveTenantRealm(): void
    {
        $this->app->instance(TenancyContext::class, \Mockery::mock(TenancyContext::class)->shouldIgnoreMissing());
        config(['database.default' => 'testing']);
        $this->app->forgetInstance(ConfigOverrideStore::class);
    }

    /** The beam, ux and config schema on a tenant's own store (as ConfigRecordingTest). */
    private function migrateOn(string $connection): void
    {
        $previous = config('database.default');
        config(['database.default' => $connection]);
        try {
            $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
            foreach (['create_beam_particles_table', 'create_beam_versions_table', 'create_activity_log_table'] as $n) {
                (require $core.'/database/migrations/shared/'.$n.'.php.stub')->up();
            }
            foreach (['create_beam_ux_entries_table', 'add_page_settings_to_beam_ux_entries_table', 'add_child_fields_to_beam_ux_entries_table',
                'add_record_page_to_beam_ux_entries_table', 'add_component_kind_to_beam_ux_entries_table', 'add_component_names_to_beam_ux_entries_table',
                'create_beam_ux_redirects_table'] as $n) {
                (require dirname(__DIR__).'/database/migrations/shared/'.$n.'.php.stub')->up();
            }
            $this->runBeamWorkflowsMigrations();
            foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'create_beam_ux_release_recordings_table'] as $n) {
                (require dirname(__DIR__)."/database/migrations/shared/{$n}.php.stub")->up();
            }
            foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $n) {
                (require dirname(__DIR__)."/vendor/splicewire/laravel-beam/database/migrations/shared/{$n}.php.stub")->up();
            }
        } finally {
            config(['database.default' => $previous]);
        }
    }
}
