<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Release\Kinds\ConfigOverrideMemberKind;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseConfigSection;

/**
 * config-sections 10b, the 08 defect (root ruling 012b6388). A config member pins the section's head draft when it joins.
 * Publishing a Release moves the published pin to the MEMBER's version, so a member left pinning an earlier or discarded
 * draft must never publish it:
 * - publish REFUSES a member whose version is not the current head draft (`release_member_not_head_draft`); nothing is
 *   re-pinned silently;
 * - Discard draft DROPS the members of draft Releases that pinned the discarded draft; a Release past draft keeps its
 *   member, and publish refuses it;
 * - putMemberByKind reads the head under the Release lock, not before it.
 *
 * No git recording is needed: every publish here is refused before any pin moves. Same database wiring as
 * ConfigRecordingTest: `central` shares the default connection's database.
 */
class ConfigMemberHeadDraftTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    private const FIXTURES = __DIR__.'/Fixtures/Config';

    private const ROOT = 'release-fixture';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $db = 'file:beam10b_'.uniqid().'?mode=memory&cache=shared';
        $shared = ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.connections.testing', $shared);
        $app['config']->set('database.connections.central', $shared);
        // 08's tenant harness: a SEPARATE database for a tenant's own store, and a POOLED tenant identical to central.
        $tdb = 'file:beam10bt_'.uniqid().'?mode=memory&cache=shared';
        $app['config']->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $tdb, 'prefix' => '', 'foreign_key_constraints' => false]);
        $app['config']->set('database.connections.tenant_pooled', $shared);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        // One author submits, approves and publishes (the review gate is not what these tests are about).
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

    private function saveDraft(string $heading): void
    {
        app(ConfigOverrideStore::class)->saveDraft(self::ROOT, [['pointer' => '/heading', 'op' => 'set', 'value' => $heading]]);
    }

    /** A Release holding the section at its current head draft. */
    private function releaseWithConfig(): BeamUxRelease
    {
        $release = app(ReleaseService::class)->create($this->page(), 'config', $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);

        return $release->fresh();
    }

    private function configMember(BeamUxRelease $release): ?BeamUxReleaseMember
    {
        return $release->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)->where('member_ref', self::ROOT)->first();
    }

    private function scope(): ConfigOverrideScope
    {
        return app(ConfigOverrideStore::class)->scope(self::ROOT);
    }

    /** Publish, expecting the 08-defect refusal; returns its message. Nothing may have moved. */
    private function assertPublishRefusedNotHead(BeamUxRelease $release): string
    {
        $publishedBefore = $this->scope()->published_version;
        try {
            app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);
            $this->fail('publish should be refused: the member pins a draft that is not the head');
        } catch (CodedValidationException $refused) {
            $messages = collect($refused->errors())->flatten()->all();
            $this->assertSame(['This Release holds an earlier draft of release-fixture. Add release-fixture to the Release again to take its current draft.'], $messages);
            $this->assertSame($publishedBefore, $this->scope()->published_version, 'no pin moved');
            $this->assertNotSame('published', $release->fresh()->status);

            return $messages[0];
        }
    }

    public function test_discard_then_save_then_publish_is_refused_and_never_publishes_the_discarded_draft(): void
    {
        // A Release past draft keeps its member through a discard, so this is the path the refusal must catch.
        $this->saveDraft('discarded heading');
        $release = $this->releaseWithConfig();
        $discarded = $this->configMember($release)->version_id;
        app(ReleaseService::class)->transition($release->fresh(), 'submit', $this->author);
        app(ReleaseService::class)->transition($release->fresh(), 'approve', $this->author);

        app(ConfigOverrideStore::class)->discardDraft(self::ROOT);
        $this->assertSame($discarded, $this->configMember($release)->version_id, 'an approved Release keeps its member');
        $this->saveDraft('new heading');
        $this->assertNotSame($discarded, $this->scope()->head_version);

        $this->assertPublishRefusedNotHead($release);
        $this->assertNotSame($discarded, $this->scope()->published_version, 'the discarded draft was never published');
    }

    public function test_a_stale_member_is_refused_at_publish_and_a_re_add_takes_the_current_draft(): void
    {
        $this->saveDraft('first');
        $release = $this->releaseWithConfig();
        $this->saveDraft('second'); // a Save draft since the section joined: the member is stale

        $this->assertPublishRefusedNotHead($release);

        // adding the section again re-pins the member to the head draft, and the refusal no longer applies
        app(ReleaseService::class)->putMemberByKind($release->fresh(), BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);
        $member = $this->configMember($release->fresh());
        $this->assertSame($this->scope()->head_version, $member->version_id);
        app(ConfigOverrideMemberKind::class)->assertPublishable($release->fresh(), $this->scope(), $member);
    }

    public function test_discard_drops_the_member_from_a_draft_release(): void
    {
        // r6 review N1: after a discard the 10b draft bar showed the plain "Joins" line while 08 still held the member. The
        // member is now dropped, so the Release and the bar agree: nothing of this section is in the Release.
        $this->saveDraft('to discard');
        $release = $this->releaseWithConfig();
        $this->assertNotNull($this->configMember($release));

        app(ConfigOverrideStore::class)->discardDraft(self::ROOT);

        $this->assertNull($this->configMember($release->fresh()), 'the member pinned to the discarded draft is dropped');
        $this->assertSame('draft', $release->fresh()->status);
    }

    public function test_a_save_draft_or_a_publish_drops_nothing(): void
    {
        $this->saveDraft('first');
        $release = $this->releaseWithConfig();
        $this->saveDraft('second'); // head moves forward: stale, not discarded
        $this->assertNotNull($this->configMember($release->fresh()), 'a Save draft keeps the member (publish refuses it as stale)');

        app(ConfigOverrideStore::class)->publish(self::ROOT); // published moves to head; head does not move back
        $this->assertNotNull($this->configMember($release->fresh()), 'a direct publish drops nothing');
    }

    public function test_put_member_by_kind_reads_the_head_under_the_release_lock(): void
    {
        // The race: a Save draft between reading the head and taking the Release lock would leave the member pinning a
        // head read outside the lock. SQLite takes no row locks, so this pins what the lock protects: the ORDER. The
        // Release is read FOR UPDATE before any read of the section scope's head.
        $this->saveDraft('head');
        $release = app(ReleaseService::class)->create($this->page(), 'config', $this->author);
        $releases = (new BeamUxRelease)->getTable();
        $scopes = (new ConfigOverrideScope)->getTable();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);

        $first = fn (string $table) => collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, '"'.$table.'"'));
        $this->assertNotFalse($first($releases), 'the Release is read');
        $this->assertNotFalse($first($scopes), 'the scope head is read');
        $this->assertLessThan($first($scopes), $first($releases), 'the Release lock is taken before the head is read');
        $this->assertSame($this->scope()->head_version, $this->configMember($release->fresh())->version_id);
    }

    public function test_a_tenant_discard_drops_the_member_on_the_tenant_store_and_leaves_central_untouched(): void
    {
        // Lead 09:43Z: tenant Releases are where 10b's tenant draft bar sends members. Same tenancy harness as 08's AC4: a
        // SECOND database is the tenant's own store; the tier follows the realm.
        // Central first. As in 08's AC4, the operator publishes heading so the tenant tier's base validates, then leaves an
        // operator DRAFT, held by a central draft Release.
        $this->migrateOn('tenant');
        $this->saveDraft('published heading');
        app(ConfigOverrideStore::class)->publish(self::ROOT);
        $this->saveDraft('operator heading');
        $central = $this->releaseWithConfig();
        $centralMember = $this->configMember($central);
        $this->assertNotNull($centralMember);
        $centralHead = $this->scope()->head_version;

        // The tenant: a tenant-tier draft (banner, ceiling tenant) held by a tenant Release on the tenant store.
        $this->enterTenantRealm();
        $tenantStore = app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $tenantStore->saveDraft(self::ROOT, [['pointer' => '/banner', 'op' => 'set', 'value' => 'tenant banner']]);
        $tenant = app(ReleaseService::class)->create($this->page(), 'tenant config', $this->author);
        app(ReleaseService::class)->putMemberByKind($tenant, BeamUxReleaseMember::KIND_CONFIG, self::ROOT, $this->author);
        $this->assertSame('tenant', $tenant->getConnectionName() ?? config('database.default'), 'the tenant Release lives on the tenant store');
        $this->assertNotNull($this->configMember($tenant->fresh()), 'precondition: the tenant Release holds the section');

        $tenantStore->discardDraft(self::ROOT);

        $this->assertNull($this->configMember($tenant->fresh()), 'the tenant member pinned to the discarded draft is dropped on the tenant store');
        $this->leaveTenantRealm();
        $this->assertSame($centralMember->version_id, $this->configMember($central->fresh())?->version_id, 'the central member is untouched');
        $this->assertSame($centralHead, $this->scope()->head_version, 'the central draft is untouched');
    }

    public function test_a_pooled_tenant_discard_never_reaches_a_central_member(): void
    {
        // A POOLED tenant's store is the SAME database as central (08's AC3 pooled harness): it cannot hold a config member
        // at all (release_members_outside_realm), and its tier has no safe store, so a tenant-tier discard there is refused
        // by laravel-beam. Whatever the store does, the central Release's member must survive.
        $this->saveDraft('operator heading');
        $central = $this->releaseWithConfig();
        $centralMember = $this->configMember($central);
        $this->assertNotNull($centralMember);

        $this->enterTenantRealm('tenant_pooled');
        try {
            app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->discardDraft(self::ROOT);
        } catch (\Illuminate\Validation\ValidationException) {
            // refused: a pooled tenant tier has no safe store of its own
        }
        $this->leaveTenantRealm();

        $this->assertSame($centralMember->version_id, $this->configMember($central->fresh())?->version_id, 'the central member survives a pooled tenant discard');
    }

    /** Enter a tenant hop: tenancy active, default connection = the tenant's store (08's harness). */
    private function enterTenantRealm(string $connection = 'tenant'): void
    {
        $tenancy = \Mockery::mock(\Splicewire\Beam\Realm\Contracts\TenancyContext::class)->shouldIgnoreMissing();
        $tenancy->shouldReceive('isActive')->andReturnTrue();
        $tenancy->shouldReceive('isMultiTenant')->andReturnTrue();
        $tenancy->shouldReceive('currentTenantId')->andReturn('tenant-1');
        $tenancy->shouldReceive('centralConnection')->andReturn('central');
        $this->app->instance(\Splicewire\Beam\Realm\Contracts\TenancyContext::class, $tenancy);
        config(['database.default' => $connection]);
        $this->app->forgetInstance(ConfigOverrideStore::class);
    }

    /** Leave the tenant hop: back to the central realm (default connection, tenancy inactive). */
    private function leaveTenantRealm(): void
    {
        $this->app->instance(\Splicewire\Beam\Realm\Contracts\TenancyContext::class,
            \Mockery::mock(\Splicewire\Beam\Realm\Contracts\TenancyContext::class)->shouldIgnoreMissing());
        config(['database.default' => 'testing']);
        $this->app->forgetInstance(ConfigOverrideStore::class);
    }

    /** Run the full beam + ux + config schema on a connection (a tenant's own store), restoring the default after (08's harness). */
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
