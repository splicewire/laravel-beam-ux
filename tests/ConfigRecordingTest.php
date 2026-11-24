<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideCounter;
use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Ux\Config\BeamUxSection;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Models\BeamUxReleaseRecording;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Recording\ConfigOverrideMirror;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;
use Splicewire\Beam\Ux\Release\Kinds\ConfigOverrideMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\EntryMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseMemberKindRegistry;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\InheritSection;
use Splicewire\Beam\Ux\Tests\Fixtures\Config\ReleaseConfigSection;
use Symfony\Component\Process\Process;

/**
 * config-sections 08: a config override rides a Release and lands in the SAME commit as the pages it published. These
 * tests gate the receipt-driven recording (ADR-0243 d3 per-kind projection, d4 one commit built from the manifest): a
 * page edit and a `beam.ux` override publish together and record one commit with both files; order of kind registration
 * does not matter; secrets never reach the mirror; a config-only Release still commits.
 *
 * NOTE (TOTS, in progress): drafted out of slot; the first slot turn runs + irons out any harness gaps. Requires
 * BEAM_RECORDING_TEST_ROOT (a disposable root) and the fixture host's node deps (esbuild) for the tsx page body.
 */
class ConfigRecordingTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    private string $repo;

    private const FIXTURES = __DIR__.'/Fixtures/Config';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Reproduce a FLAGSHIP host's wiring: `central` is a COPY of the default connection — the SAME database reached
        // through a SECOND connection, so two PDOs. A plain sqlite `:memory:` gives each connection its OWN database, so
        // it could not reproduce this; a shared-cache in-memory database lets both connections share one. This is what
        // catches config-sections 08's atomicity requirement: a config override must publish through the Release's OWN
        // connection (the default), not `central`, or the two pins split across the two PDOs and the recording breaks.
        $db = 'file:beam08_'.uniqid().'?mode=memory&cache=shared';
        $shared = ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.connections.testing', $shared);
        $app['config']->set('database.connections.central', $shared);
        // A SEPARATE database standing in for a TENANT's own store (config-sections 07 tenant tier). Its own shared-cache
        // in-memory DSN, distinct from central/testing, so the tenant tier's scope is physically separate from the
        // operator's — what a real schema/isolated tenant has. The tenant-tier behaviour test migrates it and switches the
        // default to it inside the tenant hop.
        $tdb = 'file:beam08t_'.uniqid().'?mode=memory&cache=shared';
        $app['config']->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $tdb, 'prefix' => '', 'foreign_key_constraints' => false]);
        // A POOLED tenant connection: the SAME database target as central (identical DSN), so sameDatabase() passes — a
        // tenant pooled in the central schema. Its config member must still be REFUSED by realm/tier (the scopes collide).
        $app['config']->set('database.connections.tenant_pooled', $shared);
        // Secrets are encrypted at the operator tier (ADR-0245 §6), so the app needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $root = getenv('BEAM_RECORDING_TEST_ROOT');
        if (! is_string($root) || ! is_dir($root)) {
            $this->markTestSkipped('Set BEAM_RECORDING_TEST_ROOT to an owned registered scratch root.');
        }
        $this->repo = $root.'/config-'.bin2hex(random_bytes(6));
        mkdir($this->repo, 0700);
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.name', 'Release test']);
        $this->git(['config', 'user.email', 'release@example.invalid']);
        file_put_contents($this->repo.'/unrelated', "base\n");
        $this->git(['add', '--', 'unrelated']);
        $this->git(['commit', '-m', 'baseline']);
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n");
        chmod($this->repo.'/.git/hooks/pre-commit', 0755);
        config(['filesystems.disks.recording-test' => ['driver' => 'local', 'root' => $this->repo, 'throw' => true],
            'beam.ux.storage.mirror_disk' => 'recording-test', 'beam.ux.recording.git' => 'git']);
        Storage::forgetDisk('recording-test');
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'create_beam_ux_release_recordings_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        // laravel-beam's config tables (versions + override scopes) ship in vendor.
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

        // The config section the Release carries; read its deploy default from the fixture host directory. Forget the
        // store + overlay so they re-resolve against this fresh registry (both capture it by constructor injection).
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(\Splicewire\Beam\Config\Overrides\ConfigOverlay::class);
        app(ConfigSectionRegistry::class)->section(ReleaseConfigSection::class, owner: 'qa/fixture');
    }

    public function test_a_page_edit_and_a_config_override_land_in_one_commit(): void
    {
        $base = $this->git(['rev-parse', 'HEAD']);
        [$release, $page] = $this->buildAndPublish(configFirst: false);

        $receipt = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $receipt->state, (string) $receipt->error);
        $this->assertSame('1', $this->git(['rev-list', '--count', $base.'..HEAD']), 'exactly one new commit');
        $kinds = array_column($receipt->manifest, 'kind');
        $this->assertContains(BeamUxReleaseMember::KIND_ENTRY, $kinds);
        $this->assertContains(BeamUxReleaseMember::KIND_CONFIG, $kinds, 'the config row rides the same receipt');
        $tree = $this->git(['ls-tree', '-r', '--name-only', 'HEAD']);
        $this->assertStringContainsString(ConfigOverrideMirror::pathFor('release-fixture'), $tree, 'the override file is in the commit');
        $this->assertStringContainsString('Beam-UX-Recording:', $this->git(['log', '-1', '--format=%B']), 'the trailer names the Release');
    }

    public function test_the_config_kind_registered_first_still_yields_one_commit_with_both_files(): void
    {
        $base = $this->git(['rev-parse', 'HEAD']);
        [$release] = $this->buildAndPublish(configFirst: true);

        $receipt = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $receipt->state, (string) $receipt->error);
        $this->assertSame('1', $this->git(['rev-list', '--count', $base.'..HEAD']), 'registration order does not add a second commit');
        $this->assertContains(BeamUxReleaseMember::KIND_CONFIG, array_column($receipt->manifest, 'kind'));
        $this->assertContains(BeamUxReleaseMember::KIND_ENTRY, array_column($receipt->manifest, 'kind'));
    }

    public function test_a_secret_override_is_never_written_to_the_mirror_or_the_commit(): void
    {
        app(ConfigOverrideStore::class)->saveDraft('release-fixture', [
            ['pointer' => '/heading', 'op' => 'set', 'value' => 'Operator heading'],
            ['pointer' => '/token', 'op' => 'set', 'value' => 'super-secret-value'],
        ]);
        [$release] = $this->buildAndPublish(configFirst: false, saveDraft: false);

        $committed = $this->git(['show', 'HEAD:'.ConfigOverrideMirror::pathFor('release-fixture')]);
        $this->assertStringContainsString('Operator heading', $committed, 'the non-secret override is mirrored');
        $this->assertStringNotContainsString('super-secret-value', $committed, 'the secret value is never mirrored');
    }

    public function test_a_config_only_release_records_one_commit(): void
    {
        $base = $this->git(['rev-parse', 'HEAD']);
        app(ConfigOverrideStore::class)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'Only config']]);
        $context = $this->page();
        $release = app(ReleaseService::class)->create($context, 'config only', $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);

        $receipt = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $receipt->state, (string) $receipt->error);
        $this->assertSame('1', $this->git(['rev-list', '--count', $base.'..HEAD']));
    }

    public function test_import_leaves_an_existing_secret_untouched_with_no_op(): void // (g) / test 8
    {
        $document = ['root' => 'release-fixture', 'overrides' => [
            ['pointer' => '/heading', 'op' => 'set', 'value' => 'Operator heading'],
            ['pointer' => '/token', 'op' => 'set', 'secret' => true],
        ]];
        $ops = ConfigOverrideMirror::toOps($document);
        $this->assertCount(1, $ops, 'the secret row is a marker only and yields no op');
        $this->assertSame('/heading', $ops[0]['pointer']);
        $this->assertNotContains('/token', array_column($ops, 'pointer'), 'import never touches the existing secret');
    }

    public function test_rollback_restores_the_previous_config_pin_and_a_null_previous_removes_the_override(): void // (d)
    {
        // Give the entry a PRIOR published version (v0) so the Release's rollback REVERTS it to v0 — not an entry
        // take-down, whose lifecycle is the entry workflow's concern, separate from config. The config override, first
        // published by this Release, reverts to its previous pin — null — which REMOVES it (the ticket AC).
        $page = $this->page();
        $v0 = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        app(EntryPublication::class)->publishVersions((new BeamUxEntry)->setConnection($page->getConnectionName()),
            [$page->id => $v0], $this->author, leaveRedirects: false);
        app(ConfigOverrideStore::class)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'v1']]);
        $v1 = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'span', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $release = app(ReleaseService::class)->create($page->fresh(), 'page and config', $this->author);
        app(ReleaseService::class)->putMember($release, (string) $page->id, $v1, $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);
        $this->assertNotNull(app(ConfigOverrideStore::class)->scope('release-fixture')->published_version);
        $this->git(['show', 'HEAD:'.ConfigOverrideMirror::pathFor('release-fixture')]); // the forward publish committed the file

        $afterPublish = $this->git(['rev-parse', 'HEAD']);
        $rollback = app(ReleaseService::class)->rollback($release->fresh(), $this->author);
        app(ReleaseService::class)->transition($rollback->fresh(), 'publish', $this->author);

        $this->assertNull(app(ConfigOverrideStore::class)->scope('release-fixture')->published_version,
            'a null previous pin removed the override on rollback');
        // ONE commit reverts both members: the entry body back to v0 AND the config override file deleted.
        $this->assertSame('1', $this->git(['rev-list', '--count', $afterPublish.'..HEAD']), 'the rollback made exactly one commit');
        $paths = $this->git(['show', '--name-status', '--format=', 'HEAD']);
        $this->assertStringContainsString('D'."\t".ConfigOverrideMirror::pathFor('release-fixture'), $paths,
            'the one rollback commit DELETES the override file (so a later import cannot re-introduce it)');
        try {
            $this->git(['show', 'HEAD:'.ConfigOverrideMirror::pathFor('release-fixture')]);
            $this->fail('the override file should be absent from the rollback commit');
        } catch (\Throwable) {
            // expected — the file is gone from the tree
        }
    }

    public function test_a_rollback_to_a_previous_config_version_commits_that_versions_bytes(): void // (d-commit)
    {
        // Publish config A (a config-only Release), then the second Release moves the page AND config to B; rolling the
        // second Release back reverts the config pin to A and COMMITS A's bytes (not a delete) in one commit. The page
        // (published at ev0 first) reverts to ev0 so the rollback is a revert, not an entry take-down.
        $page = $this->page();
        $ev0 = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        app(EntryPublication::class)->publishVersions((new BeamUxEntry)->setConnection($page->getConnectionName()),
            [$page->id => $ev0], $this->author, leaveRedirects: false);

        app(ConfigOverrideStore::class)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);
        $r1 = app(ReleaseService::class)->create($page->fresh(), 'config A', $this->author);
        app(ReleaseService::class)->putMemberByKind($r1, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        app(ReleaseService::class)->transition($r1->fresh(), 'publish', $this->author);
        $pinA = app(ConfigOverrideStore::class)->scope('release-fixture')->published_version;
        $bytesA = $this->git(['show', 'HEAD:'.ConfigOverrideMirror::pathFor('release-fixture')]);

        app(ConfigOverrideStore::class)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'B']]);
        $ev1 = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'span', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $r2 = app(ReleaseService::class)->create($page->fresh(), 'page and config B', $this->author);
        app(ReleaseService::class)->putMember($r2, (string) $page->id, $ev1, $this->author);
        app(ReleaseService::class)->putMemberByKind($r2, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        app(ReleaseService::class)->transition($r2->fresh(), 'publish', $this->author);
        $this->assertNotSame($bytesA, $this->git(['show', 'HEAD:'.ConfigOverrideMirror::pathFor('release-fixture')]), 'B committed different bytes');

        $afterB = $this->git(['rev-parse', 'HEAD']);
        $rollback = app(ReleaseService::class)->rollback($r2->fresh(), $this->author);
        app(ReleaseService::class)->transition($rollback->fresh(), 'publish', $this->author);

        $this->assertSame($pinA, app(ConfigOverrideStore::class)->scope('release-fixture')->published_version, 'the pin reverted to A');
        $this->assertSame('1', $this->git(['rev-list', '--count', $afterB.'..HEAD']), 'one rollback commit');
        $this->assertSame($bytesA, $this->git(['show', 'HEAD:'.ConfigOverrideMirror::pathFor('release-fixture')]),
            'the rollback commit restored A bytes (not a delete)');
    }

    public function test_import_on_a_fresh_worktree_after_a_rollback_reproduces_the_effective_config(): void // (d-import)
    {
        // After a config->null rollback, the committed tree has no override file, so importing the committed state on a
        // fresh worktree reproduces the rolled-back effective config (no override) — the committed file never disagrees
        // with the DB, so import cannot re-introduce a removed override (08's import AC).
        $page = $this->page();
        $v0 = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        app(EntryPublication::class)->publishVersions((new BeamUxEntry)->setConnection($page->getConnectionName()),
            [$page->id => $v0], $this->author, leaveRedirects: false);
        app(ConfigOverrideStore::class)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'v1']]);
        $v1 = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'span', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $release = app(ReleaseService::class)->create($page->fresh(), 'page and config', $this->author);
        app(ReleaseService::class)->putMember($release, (string) $page->id, $v1, $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);
        $rollback = app(ReleaseService::class)->rollback($release->fresh(), $this->author);
        app(ReleaseService::class)->transition($rollback->fresh(), 'publish', $this->author);

        // The committed tree has no override file for this root.
        $committed = $this->repo.'/'.ConfigOverrideMirror::pathFor('release-fixture');
        $this->assertFileDoesNotExist($committed, 'the rollback commit left no override file to import');
        // A fresh worktree (no draft, no published layer) importing the committed state keeps the override removed.
        app(ConfigOverrideStore::class)->discardDraft('release-fixture');
        $this->assertArrayNotHasKey('release-fixture',
            app(ConfigOverrideStore::class)->forTier(\Splicewire\Beam\Enums\ConfigTier::Operator)->publishedLayers(),
            'the effective config has no override after the rollback — import has nothing to re-introduce');
    }

    public function test_publishVersion_moves_the_pin_to_a_previous_version_without_touching_the_draft(): void // (d-seam)
    {
        $store = app(ConfigOverrideStore::class)->forTier(\Splicewire\Beam\Enums\ConfigTier::Operator);
        $store->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);
        $vA = $store->publish('release-fixture')->published_version;
        $store->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'B']]);
        $vB = $store->publish('release-fixture')->published_version;
        $tokenBefore = $store->publishedToken();
        $versionsBefore = count($store->versionsOf('release-fixture'));

        $store->publishVersion('release-fixture', $vA);

        $scope = $store->scope('release-fixture');
        $this->assertSame($vA, $scope->published_version, 'the published pin moved to the previous version');
        $this->assertSame($vB, $scope->head_version, 'the draft is untouched');
        $this->assertCount($versionsBefore, $store->versionsOf('release-fixture'), 'no new version was minted');
        $this->assertNotSame($tokenBefore, $store->publishedToken(), 'the published token moved');
    }

    public function test_publishVersion_null_removes_the_override_from_the_effective_config(): void // (d-seam)
    {
        $store = app(ConfigOverrideStore::class)->forTier(\Splicewire\Beam\Enums\ConfigTier::Operator);
        $store->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);
        $store->publish('release-fixture');

        $store->publishVersion('release-fixture', null);

        $scope = $store->scope('release-fixture');
        $this->assertNull($scope->published_version, 'the override row carries no published pin');
        $this->assertArrayNotHasKey('release-fixture', $store->publishedLayers(), 'the override is gone from the effective config');
    }

    public function test_publishVersion_preserves_a_pending_draft(): void // (d-seam)
    {
        $store = app(ConfigOverrideStore::class)->forTier(\Splicewire\Beam\Enums\ConfigTier::Operator);
        $store->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);
        $vA = $store->publish('release-fixture')->published_version;
        $store->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'pending']]);
        $pendingHead = $store->scope('release-fixture')->head_version;
        $this->assertNotSame($vA, $pendingHead, 'a new draft is pending before the rollback');

        $store->publishVersion('release-fixture', $vA);

        $scope = $store->scope('release-fixture');
        $this->assertSame($vA, $scope->published_version, 'the pin reverted to the previous version');
        $this->assertSame($pendingHead, $scope->head_version, 'the pending draft survives the pin move');
    }

    public function test_two_releases_on_the_same_observed_config_pin_the_second_refuses_as_stale(): void // AC2 (Spec/Standards HIGH 1)
    {
        // lock() now locks the config scope rows FOR UPDATE via allMembers() — members() is entry-only and would lock
        // nothing — so two Releases observing the same pin cannot both pass the stale check and overwrite it.
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);
        $r1 = app(ReleaseService::class)->create($this->page(), 'r1', $this->author);
        app(ReleaseService::class)->putMemberByKind($r1, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        $r2 = app(ReleaseService::class)->create($this->page(), 'r2', $this->author);
        app(ReleaseService::class)->putMemberByKind($r2, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);

        app(ReleaseService::class)->transition($r1->fresh(), 'publish', $this->author);
        $pin = app(ConfigOverrideStore::class)->scope('release-fixture')->published_version;
        $this->assertNotNull($pin, 'the first Release moved the pin');

        try {
            app(ReleaseService::class)->transition($r2->fresh(), 'publish', $this->author);
            $this->fail('the stale second Release should have refused');
        } catch (\Splicewire\Beam\Localization\CodedValidationException) {
            // expected — its observed pin moved
        }
        $this->assertSame($pin, app(ConfigOverrideStore::class)->scope('release-fixture')->published_version,
            'the stale Release did not overwrite the pin');
    }

    public function test_a_parent_set_inheriting_a_host_child_round_trips_through_mirror_and_import(): void // AC6/AC8 (Spec HIGH 3)
    {
        // An Operator set of /group owns /group/label and INHERITS the Host child /group/fixed. The mirror records that
        // inherit and import replays it, so normalization reproduces the stored entry exactly (owns /label, inherits
        // /fixed) rather than a destructive parent set the host-ceiling check refuses.
        $store = new class extends ConfigOverrideStore
        {
            public function __construct() {}

            public function tier(): ConfigTier
            {
                return ConfigTier::Operator;
            }

            /** @param  list<array<string, mixed>>  $ops */
            public function importNormalize(array $ops): array
            {
                return $this->normalize(new RegisteredConfigSection(InheritSection::class, 'host'), $ops, ['group' => ['label' => 'base', 'fixed' => 'host fixed']]);
            }
        };
        $mirror = new class($store) extends ConfigOverrideMirror
        {
            public function rowFor(string $pointer, ConfigOverrideEntry $entry): array
            {
                return $this->row($pointer, $entry);
            }
        };
        $entry = new ConfigOverrideEntry('/group', ConfigOverrideOp::Set, ['label' => 'published'], false, 'r1', 1, null, ['/fixed']);
        $document = ['root' => 'inherit-fixture', 'overrides' => [$mirror->rowFor('/group', $entry)]];
        $this->assertSame(['/fixed'], $document['overrides'][0]['inherit'] ?? null, 'the mirror records the inherited host child');

        $normalized = $store->importNormalize(ConfigOverrideMirror::toOps($document)); // no host-ceiling refusal
        $this->assertSame(['label' => 'published'], $normalized[0][2], 'the imported entry owns only /label');
        $this->assertSame(['/fixed'], $normalized[0][6], 'the imported entry inherits /fixed — byte-identical to the published entry');
    }

    public function test_publishVersion_announces_only_on_the_outer_commit(): void // AC5/AC2 (Spec HIGH 4)
    {
        // The announce (counter PUT + overlay sync) must ride the OUTERMOST commit. Published through the SAME connection
        // the Release's outer transaction runs on (as the member kind does via on()), a rollback of that outer transaction
        // reverts the pin AND never announces; a commit announces exactly once.
        $store = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->on('testing');
        $store->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);
        $v1 = $store->publish('release-fixture')->published_version;
        $tokenAtV1 = app(ConfigOverrideCounter::class)->current();

        try {
            DB::connection('testing')->transaction(function () use ($store) {
                $store->publishVersion('release-fixture', null); // revert inside the outer transaction
                throw new \RuntimeException('a later Release member fails the outer transaction');
            });
        } catch (\RuntimeException) {
            // expected — the outer transaction rolls back
        }
        $this->assertSame($v1, $store->scope('release-fixture')->published_version, 'the pin restored with the outer rollback');
        $this->assertSame($tokenAtV1, app(ConfigOverrideCounter::class)->current(),
            'the counter did NOT announce the rolled-back pin (announce deferred to the outer commit, which never came)');

        DB::connection('testing')->transaction(fn () => $store->publishVersion('release-fixture', null));
        $this->assertNull($store->scope('release-fixture')->published_version, 'the committed outer transaction moved the pin');
        $this->assertNotSame($tokenAtV1, app(ConfigOverrideCounter::class)->current(), 'the counter announced once on the outer commit');
    }

    public function test_a_failed_recording_leaves_the_publish_standing_and_commit_now_records_it(): void // (e)
    {
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 1\n");
        [$release] = $this->buildAndPublish(configFirst: false);
        $this->assertSame('published', $release->status, 'the publish stands despite the recording failure');
        $this->assertSame('failed', app(ReleaseRecording::class)->receipt($release)->state);
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n"); // Files page "commit now"
        app(ReleaseRecording::class)->retry($release->fresh(), $this->author);
        $recovered = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $recovered->state, (string) $recovered->error);
        $this->assertContains(BeamUxReleaseMember::KIND_CONFIG, array_column($recovered->manifest, 'kind'));
    }

    public function test_a_retry_after_a_failed_completion_lands_on_the_same_commit(): void // (h)
    {
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 1\n");
        [$release] = $this->buildAndPublish(configFirst: false);
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n");
        app(ReleaseRecording::class)->retry($release->fresh(), $this->author);
        $sha = app(ReleaseRecording::class)->receipt($release)->sha;
        $head = $this->git(['rev-parse', 'HEAD']);
        app(ReleaseRecording::class)->retry($release->fresh(), $this->author); // retry again
        $this->assertSame($sha, app(ReleaseRecording::class)->receipt($release)->sha, 'the retry stays on the same commit');
        $this->assertSame($head, $this->git(['rev-parse', 'HEAD']), 'no second commit');
    }

    public function test_import_writes_a_draft_and_never_publishes(): void // (f)
    {
        [$release] = $this->buildAndPublish(configFirst: false);
        $committed = $this->repo.'/'.ConfigOverrideMirror::pathFor('release-fixture');
        $this->assertFileExists($committed, 'the Release committed the override mirror');
        app(ConfigOverrideStore::class)->discardDraft('release-fixture'); // a fresh worktree has no draft
        $publishedBefore = app(ConfigOverrideStore::class)->scope('release-fixture')->published_version;
        $exit = \Illuminate\Support\Facades\Artisan::call('splicewire:beam:config:import', ['root' => 'release-fixture', 'file' => $committed]);
        $this->assertSame(0, $exit);
        $scope = app(ConfigOverrideStore::class)->scope('release-fixture');
        $this->assertNotNull($scope->head_version, 'import reproduced the draft');
        $this->assertSame($publishedBefore, $scope->published_version, 'import never moved the published pin');
    }

    public function test_an_entry_refusal_at_publish_leaves_the_config_pin_unmoved(): void // (c)
    {
        app(ConfigOverrideStore::class)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'Heading']]);
        $page = $this->page();
        $version = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        // Publish the entry at $version so the Release observes a real published pin it can later find moved.
        app(EntryPublication::class)->publishVersions((new BeamUxEntry)->setConnection($page->getConnectionName()),
            [$page->id => $version], $this->author, leaveRedirects: false);
        $release = app(ReleaseService::class)->create($page->fresh(), 'r', $this->author);
        app(ReleaseService::class)->putMember($release, (string) $page->id, $version, $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        // Make the entry member stale: publish a newer version of the page directly, so its pin moved after it joined.
        $newer = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'span', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        app(EntryPublication::class)->publishVersions((new BeamUxEntry)->setConnection($release->getConnectionName()),
            [$page->id => $newer], $this->author, [$page->id => $version], leaveRedirects: false);
        $before = app(ConfigOverrideStore::class)->scope('release-fixture')->published_version;
        try {
            app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);
            $this->fail('the stale entry should have refused the publish');
        } catch (\Throwable) {
            // expected
        }
        $this->assertSame($before, app(ConfigOverrideStore::class)->scope('release-fixture')->published_version,
            'the override pin did not move when the entry refused (one transaction)');
    }

    public function test_a_tenant_realm_release_publishes_the_tenant_pin_with_no_file_or_commit(): void // AC4 (Spec HIGH 2)
    {
        // A SECOND database stands in for the tenant's own store. An operator override sits on central; a tenant-realm
        // Release publishes the TENANT banner on the tenant store — the tier follows the Release's realm — and the
        // operator pin on central is untouched, with no file and no commit (the recording refuses while tenancy is active).
        $this->migrateOn('tenant');
        $op = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
        $op->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'operator']]);
        $operatorPin = $op->publish('release-fixture')->published_version;
        $this->assertNotNull($operatorPin);

        $this->enterTenantRealm();
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->saveDraft('release-fixture', [['pointer' => '/banner', 'op' => 'set', 'value' => 'tenant banner']]);
        $base = $this->git(['rev-parse', 'HEAD']);
        $file = $this->repo.'/'.ConfigOverrideMirror::pathFor('release-fixture');
        $this->assertFileDoesNotExist($file, 'precondition: no override file in the central mirror');
        // A CONFIG-ONLY tenant Release: no entry member fails the mirror first, so before the residency guard its
        // completion wrote the tenant's override file into the central mirror tree.
        $release = app(ReleaseService::class)->create($this->page(), 'tenant config', $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        $this->assertSame([BeamUxReleaseMember::KIND_CONFIG], $release->fresh()->allMembers()->pluck('member_kind')->all(), 'config-only');
        app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);

        $this->assertSame('published', $release->fresh()->status, 'the tenant Release published its pins');
        $this->assertNotNull(app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->scope('release-fixture')->published_version,
            'the TENANT tier pin moved on the tenant store');
        $this->assertSame($base, $this->git(['rev-parse', 'HEAD']), 'no commit while tenancy is active');
        $this->assertFileDoesNotExist($file, "no file: the tenant's override never reaches the central mirror tree");
        $this->assertNull(app(ReleaseRecording::class)->receipt($release->fresh()),
            'recording does not apply to a tenant Release: no receipt row, so no failed one');
        $this->assertNotApplicable($release->fresh());

        $this->leaveTenantRealm();
        $this->assertSame($operatorPin, app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->scope('release-fixture')->published_version,
            'the operator tier pin on central is untouched by the tenant Release');
    }

    public function test_a_tenant_release_with_a_page_reports_recording_not_applicable_not_a_mirror_failure(): void // AC4 (10b proof finding B)
    {
        // The 10b flagship proof: a tenant Release holding a PAGE answered data.recording `failed` with the mirror
        // message, because its completion asked the central mirror to write a tenant entry, which it refuses. A tenant
        // Release makes no mirror file and no commit, so the honest answer is that recording does not apply.
        $this->migrateOn('tenant');
        $this->enterTenantRealm();
        $base = $this->git(['rev-parse', 'HEAD']);
        $page = $this->page();
        $this->assertSame('tenant', $page->getConnectionName(), 'precondition: the page lives on the tenant store');
        $version = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $release = app(ReleaseService::class)->create($page->fresh(), 'tenant page', $this->author);
        app(ReleaseService::class)->putMember($release, (string) $page->id, $version, $this->author);
        app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);

        $this->assertSame('published', $release->fresh()->status, 'the tenant Release published');
        $this->assertSame($version, $page->fresh()->published_version, 'the page pin moved on the tenant store');
        $this->assertSame($base, $this->git(['rev-parse', 'HEAD']), 'no commit');
        $this->assertSame('', $this->git(['status', '--porcelain']), 'no file in the central mirror tree');
        $this->assertSame(0, BeamUxReleaseRecording::on('tenant')->count(), 'no receipt row on the tenant store');
        $this->assertNotApplicable($release->fresh());
        $this->leaveTenantRealm();
    }

    public function test_a_tenant_release_with_an_older_failed_receipt_still_reads_not_applicable(): void // AC4 (finding B, stored rows)
    {
        // Releases published before this fix stored a `failed` receipt. Residency decides the answer, not the row.
        // The section's required operator `heading` sits on central first, so the tenant's banner draft validates.
        $this->migrateOn('tenant');
        $op = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
        $op->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'operator']]);
        $op->publish('release-fixture');
        $this->enterTenantRealm();
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->saveDraft('release-fixture', [['pointer' => '/banner', 'op' => 'set', 'value' => 'tenant banner']]);
        $release = app(ReleaseService::class)->create($this->page(), 'tenant config', $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);
        (new BeamUxReleaseRecording)->setConnection('tenant')->forceFill(['release_id' => $release->id, 'manifest' => [],
            'state' => 'failed', 'error' => 'mirror:mirror_release_mirror_could_not_completed'])->save();

        $this->assertNotApplicable($release->fresh());
        $this->leaveTenantRealm();
    }

    public function test_an_operator_release_still_records_and_leaves_no_work_directory(): void // finding B (unchanged) + D
    {
        // The central answer must not change: recorded, with the commit's sha. Finding D: the attempt's private Git
        // directory under storage/framework/beam-ux-recordings/<id> is removed once the result is saved.
        $base = $this->git(['rev-parse', 'HEAD']);
        [$release] = $this->buildAndPublish(configFirst: false);

        $receipt = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $receipt->state, (string) $receipt->error);
        $head = $this->git(['rev-parse', 'HEAD']);
        $this->assertNotSame($base, $head);
        $data = \Splicewire\Beam\Ux\Data\ReleaseRecordingData::project($release, $this->author);
        $this->assertSame('recorded', $data->status);
        $this->assertSame($head, $data->sha);
        $this->assertSame(__('beam-ux::recording.status.recorded'), $data->message);
        $this->assertStringStartsWith(ReleaseRecording::workRoot((string) $receipt->id).'/attempt-', (string) $receipt->preparation,
            'precondition: the attempt ran in the per-recording work directory');
        $this->assertDirectoryDoesNotExist(ReleaseRecording::workRoot((string) $receipt->id), 'the work directory is removed after the result is saved');
        $this->assertSame($head, $this->git(['rev-parse', 'refs/beam-ux-recordings/'.$receipt->id]), 'the commit stays anchored');
    }

    public function test_a_failed_recording_keeps_its_work_directory_for_recovery(): void // finding D (only recorded is cleaned)
    {
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 1\n");
        [$release] = $this->buildAndPublish(configFirst: false);

        $receipt = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('failed', $receipt->state, 'precondition: the hook refused the commit');
        $this->assertDirectoryExists(ReleaseRecording::workRoot((string) $receipt->id), 'a failed attempt keeps its directory');
        \Illuminate\Support\Facades\File::deleteDirectory(ReleaseRecording::workRoot((string) $receipt->id));
    }

    private function assertNotApplicable(BeamUxRelease $release): void
    {
        $data = \Splicewire\Beam\Ux\Data\ReleaseRecordingData::project($release, $this->author);
        $this->assertSame('not-applicable', $data->status, 'a tenant Release reports that recording does not apply');
        $this->assertNull($data->sha);
        $this->assertFalse($data->canRetry, 'nothing to retry');
        $this->assertSame(__('beam-ux::recording.status.not-applicable'), $data->message);
        $this->assertNotSame(__('beam-ux::recording.status.failed.mirror'), $data->message);
    }

    public function test_a_config_override_is_refused_across_the_realm_boundary_both_ways(): void // AC3 (Spec HIGH 2)
    {
        // The exclusion keys on realm/tier, not just the database. The operator publishes heading on central (so the
        // tenant tier's base validates), then leaves a PENDING operator draft. If the central row leaked into a tenant
        // Release, that draft would make it admissible, so direction 1 can only pass when the leak is truly absent.
        $this->migrateOn('tenant');
        $op = app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator);
        $op->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'operator']]);
        $op->publish('release-fixture');
        $op->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'operator pending']]);
        $this->assertNotSame($op->scope('release-fixture')->published_version, $op->scope('release-fixture')->head_version,
            'precondition: the operator row on central holds a pending draft');

        // Direction 1: a TENANT Release reads only the tenant tier, which has no row for the section. The operator's
        // pending draft is invisible to it rather than foreign, so the refusal is "nothing to publish", not a realm error.
        $this->enterTenantRealm();
        $tenantRelease = app(ReleaseService::class)->create($this->page(), 'tenant', $this->author);
        try {
            app(ReleaseService::class)->putMemberByKind($tenantRelease, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
            $this->fail('a central (operator) override must not join a tenant Release');
        } catch (\Splicewire\Beam\Localization\CodedValidationException $e) {
            $this->assertSame(['release' => ['release_member_no_draft']], $e->codes());
        }
        $this->assertSame(0, $tenantRelease->fresh()->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)->count(),
            'the tenant Release holds no config member');

        // The tenant drafts banner on its own tier. Publish the operator's pending draft, so the operator tier has nothing
        // new. Direction 2: a CENTRAL Release reads only the operator tier and cannot see the tenant's draft.
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->saveDraft('release-fixture', [['pointer' => '/banner', 'op' => 'set', 'value' => 'tenant']]);
        $this->leaveTenantRealm();
        $op->publish('release-fixture');
        $centralRelease = app(ReleaseService::class)->create($this->page(), 'central', $this->author);
        try {
            app(ReleaseService::class)->putMemberByKind($centralRelease, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
            $this->fail('a tenant override must not join a central Release');
        } catch (\Splicewire\Beam\Localization\CodedValidationException $e) {
            $this->assertSame(['release' => ['release_member_no_draft']], $e->codes());
        }
        $this->assertSame(0, $centralRelease->fresh()->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)->count(),
            'the central Release holds no config member');
    }

    public function test_a_config_member_is_added_to_a_release_through_the_release_action_op(): void // AC9/browser 72002092 (Spec amend)
    {
        // Root's browser AC opens a Release holding a page edit AND the override THROUGH THE RELEASE'S OWN ACTIONS. The
        // kind-aware put command routes a non-entry member (kind + ref, no entryId) to putMemberByKind, authorized by the
        // kind's own realm-aware rule — so a config override can be added through the Release action op.
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);
        $release = app(ReleaseService::class)->create($this->page(), 'via op', $this->author);

        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'action' => 'member', 'kind' => BeamUxReleaseMember::KIND_CONFIG, 'ref' => 'release-fixture',
        ]);
        \Splicewire\Beam\Ux\Particle\ReleaseCommandOp::handle($release->fresh(), $request, $this->author);

        $this->assertSame(1, $release->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)->where('member_ref', 'release-fixture')->count(),
            'the config override member was added to the Release through the kind-aware action op');
    }

    public function test_a_pooled_tenant_release_identical_to_central_is_refused_a_config_member(): void // AC3 pooled forward (Spec/QA amend)
    {
        // POOLED tenant: its store is the SAME database AND schema as central (identical connection target), so
        // sameDatabase() passes. The operator and tenant scopes collide on one row, so a tenant Release on that connection
        // cannot carry a config override — REFUSED by realm + tier, not by database. (The REVERSE, a tenant override
        // reaching a central Release on an identical target, is enforced upstream by the tenant tier's isolation; held.)
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'operator']]);
        $this->enterTenantRealm('tenant_pooled'); // the default is a connection identical to central
        $release = app(ReleaseService::class)->create($this->page(), 'pooled tenant', $this->author);
        try {
            app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
            $this->fail('a pooled tenant Release identical to central must be refused a config member');
        } catch (\Splicewire\Beam\Localization\CodedValidationException $e) {
            // expected — the tenant tier is not isolated from the operator tier on an identical target: a REALM refusal
            $this->assertSame(['release' => ['release_members_outside_realm']], $e->codes());
        }
    }

    public function test_a_nested_key_of_a_registered_root_is_refused_as_an_unknown_section_not_outside_the_realm(): void
    {
        // `beam.ux` is the section root; `beam.ux.releases` is a key inside it, which names no section (ADR-0245 §1). The
        // refusal says so, rather than reporting a realm/connection problem the Release does not have.
        app(ConfigSectionRegistry::class)->section(BeamUxSection::class, owner: 'splicewire/laravel-beam-ux');
        $this->assertNotNull(app(ConfigSectionRegistry::class)->get('beam.ux'), 'precondition: beam.ux is registered');
        $release = app(ReleaseService::class)->create($this->page(), 'unknown section', $this->author);

        try {
            app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'beam.ux.releases', $this->author);
            $this->fail('a ref that names no registered section must be refused');
        } catch (\Splicewire\Beam\Localization\CodedValidationException $e) {
            $this->assertSame(['release' => ['release_member_unknown_section']], $e->codes());
            $this->assertStringContainsString('No config section is registered for beam.ux.releases.', implode("\n", $e->errors()['release']));
        }
    }

    public function test_a_registered_root_with_no_override_draft_is_refused_as_having_no_draft(): void
    {
        app(ConfigSectionRegistry::class)->section(BeamUxSection::class, owner: 'splicewire/laravel-beam-ux');
        $this->assertNull(app(ConfigOverrideStore::class)->scope('beam.ux'), 'precondition: no override row for beam.ux');
        $release = app(ReleaseService::class)->create($this->page(), 'no draft', $this->author);

        try {
            app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'beam.ux', $this->author);
            $this->fail('a registered section with no draft has nothing to publish');
        } catch (\Splicewire\Beam\Localization\CodedValidationException $e) {
            $this->assertSame(['release' => ['release_member_no_draft']], $e->codes());
            $this->assertStringContainsString('There is no unpublished change to beam.ux.', implode("\n", $e->errors()['release']));
        }
    }

    public function test_a_tenant_tier_draft_on_a_central_identical_target_is_refused_by_the_store(): void // AC3 reverse (PENDING 07 guard)
    {
        // The REVERSE exclusion is enforced UPSTREAM: a pooled tenant uses its own pool_<name> schema (RLS), so an
        // identical-target tenant scope cannot exist by construction — EXCEPT a tenant-tier write can fall through to
        // central's table via a `,public` search_path when the tenant schema lacks the table. 07's laravel-beam adds the
        // guardTenantTarget guard refusing that; 08's on() seam carries it (on()->guardTenantTarget(conn)). So a tenant
        // draft/write on a connection that resolves to central's own target is REFUSED by the store before anything lands.
        $this->enterTenantRealm('tenant_pooled'); // a connection identical to central (same sqlite DSN)
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant)->on('tenant_pooled')->saveDraft('release-fixture', [['pointer' => '/banner', 'op' => 'set', 'value' => 'tenant']]);
    }

    public function test_the_same_database_guard_matches_only_the_same_db_and_schema(): void // AC3 residency (probe, committed)
    {
        // The guard keys on (driver, host, port, database, schema). A copy of central (same db + schema) is the same
        // database; a schema tenant (same db, different schema) and an isolated tenant (different db) are NOT — each is its
        // own store. (Mirrors review-r1's tenant-residency probe; no PDO is opened.)
        $conn = fn (string $db, string $schema): \Illuminate\Database\Connection => new \Illuminate\Database\Connection(
            fn () => throw new \RuntimeException('no database opened'), $db, '', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => $db, 'search_path' => $schema]);
        $kind = new ConfigOverrideMemberKind;
        $same = new \ReflectionMethod($kind, 'sameDatabase');
        $central = $conn('shared', 'public');
        $this->assertTrue($same->invoke($kind, $conn('shared', 'public'), $central), 'a copy of central (same db + schema) is the same database');
        $this->assertFalse($same->invoke($kind, $conn('shared', 'tenant_a'), $central), 'a schema tenant (same db, different schema) is not central');
        $this->assertFalse($same->invoke($kind, $conn('tenant_a', 'public'), $central), 'an isolated tenant (different db) is not central');
        $this->assertTrue($same->invoke($kind, $conn('shared', 'tenant_a'), $conn('shared', 'tenant_a')), 'a schema tenant matches its own store');
    }

    public function test_the_config_scope_row_lock_blocks_a_concurrent_publish_on_postgres(): void // AC2 lock (QA, PG-only)
    {
        // The stale-pin refusal rests on a FOR UPDATE row lock. Prove it on PostgreSQL (SQLite's lockForUpdate is a no-op):
        // connection A holds the config scope lock in an open transaction; connection B, with a short lock_timeout, blocks
        // and fails. Skipped under SQLite — the private-PG like-for-like lane (BEAM_TEST_PGSQL_DATABASE) executes it.
        $pgDb = getenv('BEAM_TEST_PGSQL_DATABASE');
        if (! is_string($pgDb) || $pgDb === '') {
            $this->markTestSkipped('FOR UPDATE row-lock contention requires PostgreSQL (BEAM_TEST_PGSQL_DATABASE); the private-PG lane runs it.');
        }
        $pg = fn (): array => ['driver' => 'pgsql', 'host' => getenv('BEAM_TEST_PGSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('BEAM_TEST_PGSQL_PORT') ?: 5432), 'database' => $pgDb, 'search_path' => 'public',
            'username' => getenv('BEAM_TEST_PGSQL_USERNAME') ?: 'postgres', 'password' => getenv('BEAM_TEST_PGSQL_PASSWORD') ?: ''];
        config(['database.connections.pg_lock_a' => $pg(), 'database.connections.pg_lock_b' => $pg()]);
        $previous = config('database.default');
        config(['database.default' => 'pg_lock_a']);
        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $n) {
            (require dirname(__DIR__)."/vendor/splicewire/laravel-beam/database/migrations/shared/{$n}.php.stub")->up();
        }
        config(['database.default' => $previous]);
        app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->on('pg_lock_a')->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'A']]);

        $a = DB::connection('pg_lock_a');
        $a->beginTransaction();
        try {
            \Splicewire\Beam\Config\Overrides\ConfigOverrideScope::on('pg_lock_a')->where('section', 'release-fixture')->lockForUpdate()->get();
            $b = DB::connection('pg_lock_b');
            $b->statement("SET lock_timeout = '400ms'");
            try {
                \Splicewire\Beam\Config\Overrides\ConfigOverrideScope::on('pg_lock_b')->where('section', 'release-fixture')->lockForUpdate()->get();
                $this->fail('the second connection should have blocked on the FOR UPDATE row lock');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertTrue(str_contains(strtolower($e->getMessage()), 'lock') || str_contains(strtolower($e->getMessage()), 'timeout'),
                    'the second publish blocked on the row lock: '.$e->getMessage());
            }
        } finally {
            $a->rollBack();
            \Splicewire\Beam\Config\Overrides\ConfigOverrideScope::on('pg_lock_a')->where('section', 'release-fixture')->delete();
        }
    }

    /** Enter a tenant hop: the default connection becomes the tenant's store ($connection) and tenancy is active. */
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

    /** Run the full beam + ux + config schema on a connection (a tenant's own store), restoring the default after. */
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

    /**
     * Build a Release holding a page edit and a `release-fixture` override, then publish it. With `$configFirst`, the
     * config kind is registered BEFORE the entry kind, to prove registration order is not load-bearing.
     *
     * @return array{BeamUxRelease, BeamUxEntry}
     */
    private function buildAndPublish(bool $configFirst, bool $saveDraft = true): array
    {
        if ($configFirst) {
            $this->app->instance(ReleaseMemberKindRegistry::class, (new ReleaseMemberKindRegistry)
                ->register(new ConfigOverrideMemberKind)->register(new EntryMemberKind));
        }
        if ($saveDraft) {
            app(ConfigOverrideStore::class)->forTier(\Splicewire\Beam\Enums\ConfigTier::Operator)->saveDraft('release-fixture', [['pointer' => '/heading', 'op' => 'set', 'value' => 'Operator heading']]);
        }
        $page = $this->page();
        $version = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $release = app(ReleaseService::class)->create($page->fresh(), 'page and config', $this->author);
        app(ReleaseService::class)->putMember($release, (string) $page->id, $version, $this->author);
        app(ReleaseService::class)->putMemberByKind($release, BeamUxReleaseMember::KIND_CONFIG, 'release-fixture', $this->author);
        app(ReleaseService::class)->transition($release->fresh(), 'publish', $this->author);
        $this->assertSame('published', $release->fresh()->status);

        return [$release->fresh(), $page];
    }

    private function page(): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => 'about-'.Str::random(4), 'title' => 'About', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'about-'.Str::random(4)]);
    }

    private function git(array $args): string
    {
        $process = new Process(['git', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
