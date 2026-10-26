<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Components\ComponentDelivery;
use Splicewire\Beam\Ux\Http\Controllers\EntryArtifactController;
use Splicewire\Beam\Ux\Lifecycle\EntryDuplicator;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/** Review regressions, using the same fresh native database and compiler as the publication proof. */
class AuthoredComponentReviewTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['beam.taxonomy.models.tag' => Fixtures\Tag::class]);
        \Illuminate\Support\Facades\Schema::create('tags', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->nullable();
            $table->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('taggables', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('tag_id');
            $table->uuid('taggable_id');
            $table->string('taggable_type');
        });
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        // This mechanism fixture deliberately admits entry creation, including its concrete writer subject.
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AuthoredComponentReviewTestCreatePolicy::class);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
    }

    private function node(string $name): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'dynamic' => false, 'props' => [], 'children' => []];
    }

    private function reviewComponent(string $slug): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $slug, 'type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored', 'realm' => 'site']);
    }

    private function body(string $name, array $uses = []): array
    {
        return [array_replace($this->node('div'), ['children' => array_map($this->node(...), $uses), 'component' => ['name' => $name, 'props' => []]])];
    }

    public function test_guest_artifact_route_refuses_a_never_published_component_draft(): void
    {
        $entry = $this->reviewComponent('secret-draft');
        $state = app(EntryPublication::class)->draftFromBase($entry, $this->body('SecretDraft'), null);
        $this->assertNotNull(app(EntryArtifactStore::class)->readVersion($entry->fresh(), $state->headVersion));
        Route::get('/review-artifact/{entry}/{version?}', EntryArtifactController::class)->defaults('beamUxRealm', 'site');
        $this->get('/review-artifact/'.$entry->id)->assertNotFound();
        $this->get('/review-artifact/'.$entry->id.'/'.app(EntryArtifactStore::class)->versionKey($state->headVersion))->assertNotFound();
    }

    public function test_clear_then_delete_cannot_remove_a_pinned_component_in_use(): void
    {
        $entry = $this->reviewComponent('used-card');
        $publication = app(EntryPublication::class);
        $draft = $publication->recordDraft($entry, $this->body('UsedCard'));
        $publication->publishVersion($entry->fresh(), $draft->headVersion);
        $page = BeamUxEntry::create(['slug' => 'using-page', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $publication->recordDraft($page, [$this->node('UsedCard')]);
        $publication->clear($entry->fresh());
        $this->assertSame([], app(AuthoredComponents::class)->working($entry->fresh()));
        $this->expectException(ValidationException::class);
        $entry->fresh()->delete();
    }

    public function test_duplicate_preserves_explicit_authored_kind(): void
    {
        $entry = $this->reviewComponent('copy-card');
        app(EntryPublication::class)->recordDraft($entry, $this->body('CopyCard'));
        $page = BeamUxEntry::create(['slug' => 'original-copy-user', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        app(EntryPublication::class)->recordDraft($page, [$this->node('CopyCard')]);
        $copy = app(EntryDuplicator::class)->duplicate($entry->fresh());
        $this->assertSame($entry->component_kind, $copy->component_kind);
        app(EntryPublication::class)->draftFromBase($copy->fresh(), $this->body('CopyCardCopy'), null);
        $this->assertSame('CopyCardCopy', $copy->fresh()->getAttribute('component_name'));
        $this->assertSame('CopyCard', $entry->fresh()->getAttribute('component_name'));
    }

    public function test_transient_empty_source_read_keeps_a_current_artifact(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'transient-source', 'type' => 'component', 'format' => 'tsx', 'realm' => 'site', 'particle_id' => 'temporarily-unreadable']);
        $store = app(EntryArtifactStore::class);
        $store->put($entry, 'export default function Safe() {}');
        $path = $store->path($entry);
        $this->assertSame($path, app(CompileEntryBody::class)->forEntry($entry));
        $this->assertTrue($store->has($entry));
    }

    public function test_historical_duplicate_restore_is_refused_without_moving_head(): void
    {
        $publication = app(EntryPublication::class);
        $first = $this->reviewComponent('rename-first');
        $old = $publication->recordDraft($first, $this->body('OriginalName'));
        $publication->recordDraft($first->fresh(), $this->body('RenamedFirst'));
        $second = $this->reviewComponent('rename-second');
        $publication->recordDraft($second, $this->body('OriginalName'));
        $before = $publication->state($first->fresh())->headVersion;
        try {
            $publication->restore($first->fresh(), $old->headVersion);
            $this->fail('Duplicate restore succeeded');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('unique', $error->getMessage());
        }
        $this->assertSame($before, $publication->state($first->fresh())->headVersion);
    }

    public function test_broken_duplicate_profile_rows_do_not_prevent_editing_an_unrelated_page(): void
    {
        $publication = app(EntryPublication::class);
        $one = $this->reviewComponent('broken-one');
        $two = $this->reviewComponent('broken-two');
        $publication->recordDraft($one, $this->body('RepeatedName'));
        $publication->recordDraft($two, $this->body('HealthyName'));
        // The native duplicator exposes a legacy invalid declaration without bypassing a save test.
        $duplicate = app(EntryDuplicator::class)->duplicate($one->fresh());
        $duplicate->forceFill(['component_kind' => 'authored'])->save();
        $page = BeamUxEntry::create(['slug' => 'repairable-page', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $profile = app(ManifestProfileResolver::class)->resolve($page);
        $this->assertContains('HealthyName', array_column($profile['nodes'], 'name'));
        $this->assertContains('RepeatedName', array_column($profile['nodes'], 'name'));
        $this->assertSame('RepeatedNameCopy', app(AuthoredComponents::class)->definition(app(AuthoredComponents::class)->working($duplicate))->name);
        $publication->recordDraft($page, [$this->node('div')]);
        $this->assertNotNull($publication->state($page->fresh())->headVersion);
    }

    public function test_unused_code_islands_do_not_create_component_delivery_or_query_the_realm(): void
    {
        $entry = $this->reviewComponent('unused');
        app(EntryPublication::class)->recordDraft($entry, $this->body('Unused'));
        $page = BeamUxEntry::create(['slug' => 'code-page', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $connection = $page->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $out = app(ComponentDelivery::class)->published($page, [$this->node('RegisteredCode')], Request::create('/'), 'site');
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $this->assertSame([], $out, 'Code islands are not Authored dependencies');
        $this->assertLessThanOrEqual(1, count(array_filter($queries, fn ($query) => str_contains($query['query'], 'select * from "beam_ux_entries"'))), 'Only one name-filtered lookup is needed');
    }

    public function test_restore_refuses_a_cycle_and_keeps_the_current_head(): void
    {
        $pub = app(EntryPublication::class);
        $a = $this->reviewComponent('cycle-a');
        $b = $this->reviewComponent('cycle-b');
        $pub->recordDraft($a, $this->body('CycleA'));
        $oldB = $pub->recordDraft($b, $this->body('CycleB', ['CycleA']));
        $pub->recordDraft($b->fresh(), $this->body('CycleB'));
        $pub->recordDraft($a->fresh(), $this->body('CycleA', ['CycleB']));
        $before = $pub->state($b->fresh())->headVersion;
        try {
            $pub->restore($b->fresh(), $oldB->headVersion);
            $this->fail('Cyclic restore succeeded');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('cycle', $error->getMessage());
        }
        $this->assertSame($before, $pub->state($b->fresh())->headVersion);
    }

    public function test_unreadable_and_cyclic_stored_rows_are_doctor_findings_not_realm_outages(): void
    {
        $pub = app(EntryPublication::class);
        $good = $this->reviewComponent('healthy');
        $pub->recordDraft($good, $this->body('Healthy'));
        $cycle = $this->reviewComponent('stored-cycle');
        $pub->recordDraft($cycle, $this->body('StoredCycle'));
        $bad = $this->reviewComponent('unreadable');
        $bad->forceFill(['particle_id' => 'missing-particle'])->save();
        $cycle->fresh()->particle->forceFill(['payload' => $this->body('StoredCycle', ['StoredCycle'])])->save();
        app(\Splicewire\Beam\Ux\Components\ComponentScope::class)->forget();
        $profile = app(ManifestProfileResolver::class)->resolve($good->fresh());
        $this->assertContains('Healthy', array_column($profile['nodes'], 'name'));
        $this->assertNotContains('StoredCycle', array_column($profile['nodes'], 'name'));
        $findings = app(\Splicewire\Beam\Ux\Doctor\AuthoredComponentAudit::class)->run();
        $details = implode(' ', array_map(fn ($f) => $f->detail, $findings));
        $this->assertStringContainsString($bad->id, $details);
        $this->assertStringContainsString($cycle->id, $details);
        $this->assertStringNotContainsString('missing-particle', $details);
    }

    public function test_database_name_reservation_refuses_an_independent_competing_writer(): void
    {
        $one = $this->reviewComponent('reservation-one');
        $two = $this->reviewComponent('reservation-two');
        app(EntryPublication::class)->recordDraft($one, $this->body('ReservedName'));
        try {
            $two->getConnection()->transaction(fn () => app(AuthoredComponents::class)->reserveName($two, $this->body('ReservedName')));
            $this->fail('The database allowed two reservations');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('unique', $error->getMessage());
        }
        $this->assertNull($two->fresh()->getAttribute('component_name'));
        $this->assertNull($two->fresh()->particle_id);
    }

    public function test_publish_refuses_a_current_name_that_does_not_match_the_pin_until_same_release(): void
    {
        $pub = app(EntryPublication::class);
        $component = $this->reviewComponent('rename-pinned');
        $old = $pub->recordDraft($component, $this->body('OldName'));
        $pub->publishVersion($component->fresh(), $old->headVersion);
        $new = $pub->recordDraft($component->fresh(), $this->body('NewName'));
        $page = BeamUxEntry::create(['slug' => 'new-name-user', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $draft = $pub->recordDraft($page, [$this->node('NewName')]);
        try {
            $pub->publishVersion($page->fresh(), $draft->headVersion);
            $this->fail('Mismatched pinned name published');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('renamed', $error->getMessage());
        }
        $pub->publishVersions($page->fresh(), [$component->id => $new->headVersion, $page->id => $draft->headVersion]);
        $this->assertSame('NewName', $component->fresh()->getAttribute('published_component_name'));
    }

    public function test_public_lookup_reads_only_referenced_components_and_memoizes_snapshots(): void
    {
        $pub = app(EntryPublication::class);
        $used = $this->reviewComponent('perf-used');
        $draft = $pub->recordDraft($used, $this->body('PerfUsed'));
        $pub->publishVersion($used->fresh(), $draft->headVersion);
        foreach (range(1, 8) as $i) {
            $other = $this->reviewComponent('unused-'.$i);
            $pub->recordDraft($other, $this->body('Unused'.$i));
        }
        $page = BeamUxEntry::create(['slug' => 'perf-page', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        Route::get('/perf-artifact/{entry}/{version}', fn () => '')->name('review.perf.artifact');
        Route::getRoutes()->refreshNameLookups();
        $route = Route::get('/perf-page', fn () => '')->defaults('beamUxArtifactRoute', 'review.perf.artifact');
        $request = Request::create('/perf-page');
        $request->setRouteResolver(fn () => $route);
        app(\Splicewire\Beam\Ux\Components\ComponentScope::class)->forget();
        $connection = $page->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        foreach (range(1, 3) as $call) {
            $out = app(ComponentDelivery::class)->published($page, [$this->node('PerfUsed')], $request, 'site');
            $this->assertCount(1, $out);
            $this->assertNotNull($out[0]->url);
        }
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $lookups = array_filter($queries, fn ($q) => str_contains($q['query'], 'select * from "beam_ux_entries"') && str_contains($q['query'], 'published_component_name'));
        $this->assertCount(1, $lookups);
        $this->assertStringContainsString(' in ', array_values($lookups)[0]['query']);
        $this->assertSame(['site', 'authored', 'PerfUsed'], array_values($lookups)[0]['bindings']);
        $versions = array_filter($queries, fn ($q) => str_contains($q['query'], 'from "beam_versions"'));
        $this->assertCount(1, $versions);
    }

    public function test_public_component_cache_validator_changes_with_its_pin_without_blanket_private_cache(): void
    {
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return response()->json(['props' => $props]);
            }
        });
        Route::beamUxSite('test', withNav: false);
        $root = BeamUxEntry::rootFor();
        $pub = app(EntryPublication::class);
        $component = $this->reviewComponent('cache-card');
        $draft = $pub->recordDraft($component, $this->body('CacheCard'));
        $pub->publishVersion($component->fresh(), $draft->headVersion);
        $page = BeamUxEntry::create(['slug' => 'cache-page', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site', 'parent_id' => $root->id, 'segment' => 'cache-page']);
        $saved = $pub->recordDraft($page, [$this->node('CacheCard')]);
        $pub->publishVersion($page->fresh(), $saved->headVersion);
        $first = $this->get('/cache-page')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertStringNotContainsString('no-store', $first->headers->get('Cache-Control'));
        $newBody = $this->body('CacheCard');
        $newBody[0]['children'] = [['kind' => 'text', 'value' => 'Changed']];
        $new = $pub->recordDraft($component->fresh(), $newBody);
        $pub->publishVersion($component->fresh(), $new->headVersion);
        $second = $this->get('/cache-page')->assertOk();
        $this->assertNotSame($etag, $second->headers->get('ETag'));
        $component->fresh()->forceFill(['access' => ['auth']])->save();
        $this->get('/cache-page')->assertOk()->assertJsonPath('props.artifact.components.0.url', null);
    }

    public function test_open_release_snapshot_still_protects_a_cleared_component_from_delete(): void
    {
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
        $pub = app(EntryPublication::class);
        $component = $this->reviewComponent('release-only-component');
        $draft = $pub->recordDraft($component, $this->body('ReleaseOnly'));
        $pub->publishVersion($component->fresh(), $draft->headVersion);
        $page = BeamUxEntry::create(['slug' => 'release-only-page', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $held = $pub->recordDraft($page, [$this->node('ReleaseOnly')]);
        $pub->recordDraft($page->fresh(), [$this->node('div')]);
        $release = (string) \Illuminate\Support\Str::uuid();
        $page->getConnection()->table('beam_ux_releases')->insert(['id' => $release, 'title' => 'Held snapshot', 'realm' => 'site', 'created_by' => 'test:owner', 'edited_by' => 'test:owner', 'status' => 'draft']);
        $page->getConnection()->table('beam_ux_release_members')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'release_id' => $release, 'entry_id' => $page->id, 'version_id' => $held->headVersion]);
        $pub->clear($component->fresh());
        $this->assertSame([$page->id], app(AuthoredComponents::class)->usedBy($component->fresh()));
        $this->expectException(ValidationException::class);
        $component->fresh()->delete();
    }

    public function test_code_only_page_headers_are_unchanged_when_an_unused_authored_component_exists(): void
    {
        $this->app->instance(\Schemastud\Blockdoc\Contracts\ManifestNodeSource::class, new class implements \Schemastud\Blockdoc\Contracts\ManifestNodeSource
        {
            public function profiles(): array
            {
                return ['content'];
            }

            public function nodeClassesFor(string $profile): array
            {
                return [];
            }

            public function docAdmitsFor(string $profile): ?array
            {
                return null;
            }

            public function marksFor(string $profile): array
            {
                return [];
            }

            public function manifest(string $profile): array
            {
                return ['nodes' => [['name' => 'CodeWidget', 'category' => 'html', 'admitsChildCategories' => [], 'admitsText' => false, 'slots' => [], 'attrsSchema' => ['type' => 'object', 'additionalProperties' => false]]]];
            }
        });
        $this->app->bind(\Splicewire\Beam\Ux\Http\EntryRenderer::class, fn () => new class implements \Splicewire\Beam\Ux\Http\EntryRenderer
        {
            public function render(string $page, array $props): \Symfony\Component\HttpFoundation\Response
            {
                return response()->json(['props' => $props]);
            }
        });
        Route::beamUxSite('test', withNav: false);
        $root = BeamUxEntry::rootFor();
        $pub = app(EntryPublication::class);
        $page = BeamUxEntry::create(['slug' => 'only-code', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site', 'parent_id' => $root->id, 'segment' => 'only-code']);
        $version = $pub->recordDraft($page, [$this->node('CodeWidget')]);
        $pub->publishVersion($page->fresh(), $version->headVersion);
        $before = $this->get('/only-code')->assertOk();
        $cache = $before->headers->get('Cache-Control');
        $etag = $before->headers->get('ETag');
        $unused = $this->reviewComponent('unused-in-realm');
        $pub->recordDraft($unused, $this->body('UnusedInRealm'));
        $after = $this->get('/only-code')->assertOk();
        $this->assertSame($cache, $after->headers->get('Cache-Control'));
        $this->assertSame($etag, $after->headers->get('ETag'));
        $this->assertStringNotContainsString('no-store', $after->headers->get('Cache-Control'));
        $this->assertSame([], $after->json('props.artifact.components') ?? []);
        fwrite(STDOUT, '\nCODE_ONLY_HEADERS '.json_encode(['before' => $cache, 'after' => $after->headers->get('Cache-Control'), 'etagBefore' => $etag, 'etagAfter' => $after->headers->get('ETag')]).'\n');
    }

    public function test_native_empty_working_head_does_not_bypass_the_delete_guard(): void
    {
        $component = $this->reviewComponent('native-cleared');
        $pub = app(EntryPublication::class);
        $draft = $pub->recordDraft($component, $this->body('NativeCleared'));
        $pub->publishVersion($component->fresh(), $draft->headVersion);
        $page = BeamUxEntry::create(['slug' => 'native-clear-user', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $pub->recordDraft($page, [$this->node('NativeCleared')]);
        // Isolate the deletion gate from the separately tested Clear response projection.
        $pub->write($component->fresh(), []);
        app(\Rushing\Versioning\Contracts\VersionStore::class)->snapshot($component->fresh()->particle, 'cleared native head');
        $this->assertSame([], app(AuthoredComponents::class)->working($component->fresh()));
        $denied = false;
        $otherFailure = null;
        try {
            $component->fresh()->delete();
        } catch (ValidationException) {
            $denied = true;
        } catch (\Throwable $error) {
            $otherFailure = $error::class;
        }
        $this->assertTrue($denied, 'The in-use gate did not refuse before deletion; later failure: '.($otherFailure ?? 'none'));
        $this->assertNull($component->fresh()->deleted_at);
    }

    public function test_a_renamed_pending_head_does_not_release_its_still_published_name(): void
    {
        $pub = app(EntryPublication::class);
        $first = $this->reviewComponent('pinned-name-owner');
        $old = $pub->recordDraft($first, $this->body('HeldPinName'));
        $pub->publishVersion($first->fresh(), $old->headVersion);
        $new = $pub->recordDraft($first->fresh(), $this->body('NewOwnerName'));
        $second = $this->reviewComponent('waiting-name-owner');
        try {
            $pub->recordDraft($second, $this->body('HeldPinName'));
            $this->fail('Reservation ignored the published name');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('unique', $error->getMessage());
        }
        // A pre-existing historical snapshot still reaches the publish guard, including a same-Release swap.
        $waiting = $pub->recordDraft($second->fresh(), $this->body('TemporaryName'));
        $particle = $second->fresh()->particle;
        $particle->payload = $this->body('HeldPinName');
        $particle->save();
        $version = app(\Rushing\Versioning\Contracts\VersionStore::class)->snapshot($particle->fresh(), 'historical name');
        $waiting->headVersion = (string) $version->id;
        try {
            $pub->publishVersion($second->fresh(), $waiting->headVersion);
            $this->fail('A pending rename released a still-published name');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('unique', $error->getMessage());
        }
        $this->assertNull($second->fresh()->published_version);
        $pub->publishVersions($first->fresh(), [$first->id => $new->headVersion, $second->id => $waiting->headVersion]);
        $this->assertSame('NewOwnerName', $first->fresh()->published_component_name);
        $this->assertSame('HeldPinName', $second->fresh()->published_component_name);
    }

    public function test_case_insensitive_reservations_and_delete_release_names(): void
    {
        $pub = app(EntryPublication::class);
        $one = $this->reviewComponent('case-one');
        $pub->recordDraft($one, $this->body('CaseName'));
        $two = $this->reviewComponent('case-two');
        try {
            $pub->recordDraft($two, $this->body('Casename'));
            $this->fail('Case variant reserved');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('unique', $error->getMessage());
        }
        $one->fresh()->delete();
        $pub->recordDraft($two->fresh(), $this->body('CaseName'));
        $this->assertSame('CaseName', $two->fresh()->component_name);
        try {
            $one->fresh()->restore();
            $this->fail('Restoration stole a reused name');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('unique', $error->getMessage());
        }
    }

    public function test_memo_invalidation_never_downgrades_private_cache(): void
    {
        $scope = app(\Splicewire\Beam\Ux\Components\ComponentScope::class);
        $scope->privateCache = true;
        $scope->documents['old'] = [];
        $scope->forget();
        $this->assertTrue($scope->privateCache);
        $this->assertSame([], $scope->documents);
    }

    public function test_reservation_retries_once_then_refuses_without_committing(): void
    {
        $components = app(AuthoredComponents::class);
        $connection = (new BeamUxEntry)->getConnection();
        $calls = 0;
        $result = $components->reservationTransaction($connection, function () use (&$calls) {
            if (++$calls === 1) {
                throw new \PDOException('deadlock detected', 40001);
            }

            return 'saved';
        });
        $this->assertSame('saved', $result);
        $this->assertSame(2, $calls);
        $calls = 0;
        $components->reservationTransaction($connection, function () use ($components, $connection, &$calls) {
            return $components->reservationTransaction($connection, function () use (&$calls) {
                if (++$calls === 1) {
                    throw new \PDOException('deadlock detected', 40001);
                }
            });
        });
        $this->assertSame(2, $calls);
        $calls = 0;
        try {
            $components->reservationTransaction($connection, function () use (&$calls) {
                $calls++;
                throw new \PDOException('deadlock detected', 40001);
            });
            $this->fail('Exhausted conflict escaped as success');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('conflicted', $error->getMessage());
        }
        $this->assertSame(2, $calls);
    }

    public function test_unreadable_unrelated_rows_do_not_block_unused_component_deletion(): void
    {
        $entry = $this->reviewComponent('deletable');
        app(EntryPublication::class)->recordDraft($entry, $this->body('Deletable'));
        BeamUxEntry::create(['slug' => 'broken-storage', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site', 'particle_id' => 'missing-row']);
        $this->assertSame([], app(AuthoredComponents::class)->usedBy($entry->fresh()));
        $this->assertNotEmpty(app(\Splicewire\Beam\Ux\Components\ComponentScope::class)->issues);
        $entry->fresh()->delete();
        $this->assertNotNull($entry->fresh()->deleted_at);
    }

    public function test_new_name_migration_upgrades_an_already_installed_kind_stub(): void
    {
        $migration = require dirname(__DIR__).'/database/migrations/shared/add_component_names_to_beam_ux_entries_table.php.stub';
        $migration->down();
        $schema = (new BeamUxEntry)->getConnection()->getSchemaBuilder();
        $this->assertTrue($schema->hasColumn('beam_ux_entries', 'component_kind'));
        $this->assertFalse($schema->hasColumn('beam_ux_entries', 'component_name'));
        $entry = $this->reviewComponent('old-host');
        app(AuthoredComponents::class)->reserveName($entry, $this->body('OldHost'));
        app(AuthoredComponents::class)->setPublishedName($entry, $this->body('OldHost'));
        $this->assertNull($entry->getAttribute('published_component_name'));
        $migration->up();
        $migration->up();
        $this->assertTrue($schema->hasColumn('beam_ux_entries', 'component_name'));
        $this->assertNull($entry->fresh()->component_name);
    }

    public function test_legacy_unreserved_duplicate_preserves_reservation_holders_profile_and_edit(): void
    {
        $pub = app(EntryPublication::class);
        $one = $this->reviewComponent('reservation-holder');
        $pub->recordDraft($one, $this->body('ReservedOriginal'));
        $page = BeamUxEntry::create(['slug' => 'holder-user', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $pub->recordDraft($page, [$this->node('ReservedOriginal')]);
        $copy = app(EntryDuplicator::class)->duplicate($one->fresh());
        $copy->forceFill(['component_name' => null])->save();
        $particle = $copy->particle;
        $particle->payload = $this->body('ReservedOriginal');
        $particle->save();
        app(\Splicewire\Beam\Ux\Components\ComponentScope::class)->forget();
        $profile = app(ManifestProfileResolver::class)->resolve($one->fresh());
        $this->assertContains('ReservedOriginal', array_column($profile['nodes'], 'name'));
        $pub->recordDraft($one->fresh(), $this->body('ReservedOriginal'));
        $copy->fresh()->delete();
        $this->assertNull($one->fresh()->deleted_at);
    }

    public function test_soft_delete_releases_a_reservation_without_altering_other_rows(): void
    {
        $pub = app(EntryPublication::class);
        $one = $this->reviewComponent('deleted-owner');
        $pub->recordDraft($one, $this->body('FreedName'));
        $one->fresh()->delete();
        $this->assertNull($one->fresh()->component_name);
        $this->assertNull($one->fresh()->published_component_name);
        $two = $this->reviewComponent('new-owner');
        $pub->recordDraft($two, $this->body('FreedName'));
        $this->assertSame('FreedName', $two->fresh()->component_name);
        $this->assertNotNull($one->fresh()->deleted_at);
    }

    public function test_restore_refuses_a_published_name_reused_since_soft_delete_atomically(): void
    {
        $pub = app(EntryPublication::class);
        $one = $this->reviewComponent('restore-old-publisher');
        $old = $pub->recordDraft($one, $this->body('RestoreCard'));
        $pub->publishVersion($one->fresh(), $old->headVersion);
        $pub->recordDraft($one->fresh(), $this->body('RestoreCardTwo'));
        $one->fresh()->delete();
        $two = $this->reviewComponent('restore-new-publisher');
        $new = $pub->recordDraft($two, $this->body('RestoreCard'));
        $pub->publishVersion($two->fresh(), $new->headVersion);
        try {
            $one->fresh()->restore();
            $this->fail('Restore recreated a published name collision');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('published', strtolower($error->getMessage()));
        }
        $this->assertNotNull($one->fresh()->deleted_at);
        $this->assertNull($one->fresh()->component_name);
        $this->assertNull($one->fresh()->published_component_name);
        $this->assertSame('RestoreCard', $two->fresh()->published_component_name);
    }

    public function test_soft_delete_restore_rolls_back_reservations_and_row_on_failure(): void
    {
        $one = $this->reviewComponent('restore-rollback');
        app(EntryPublication::class)->recordDraft($one, $this->body('RollbackRestore'));
        $one->fresh()->delete();
        BeamUxEntry::restored(function (BeamUxEntry $entry) use ($one) {
            if ($entry->id === $one->id) {
                throw new \RuntimeException('injected restoration failure');
            }
        });
        try {
            $one->fresh()->restore();
            $this->fail('Injected restoration failure was hidden');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected restoration failure', $error->getMessage());
        }
        $this->assertNotNull($one->fresh()->deleted_at);
        $this->assertNull($one->fresh()->component_name);
    }

    public function test_state_mutating_write_retry_refetches_entry_and_emits_only_after_commit(): void
    {
        $entry = $this->reviewComponent('retry-fresh-model');
        $calls = $events = 0;
        $eventLevels = [];
        app('events')->listen(\Splicewire\Beam\Events\BeamParticlePersisted::class, function ($event) use (&$events, &$eventLevels) {
            $events++;
            $eventLevels[] = $event->record->getConnection()->transactionLevel();
        });
        BeamUxEntry::saved(function (BeamUxEntry $saved) use ($entry, &$calls) {
            if ($saved->id === $entry->id && $saved->particle_id !== null && ++$calls === 1) {
                throw new \PDOException('deadlock detected', 40001);
            }
        });
        $written = app(EntryPublication::class)->write($entry, $this->body('RetryFreshModel'));
        $fresh = $entry->fresh();
        $this->assertSame('RetryFreshModel', $fresh->component_name);
        $this->assertSame($written['key'], $fresh->particle_id);
        $this->assertSame(1, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(1, $events);
        $this->assertSame([0], $eventLevels);
        $this->assertSame(2, $calls);
    }

    public function test_lock_wait_timeout_gets_one_retry_then_clear_refusal(): void
    {
        $calls = 0;
        try {
            app(AuthoredComponents::class)->reservationTransaction((new BeamUxEntry)->getConnection(), function () use (&$calls) {
                $calls++;
                throw new \PDOException('Lock wait timeout exceeded; try restarting transaction', 1205);
            });
            $this->fail('Lock timeout escaped as success');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('conflicted', $error->getMessage());
        }
        $this->assertSame(2, $calls);
    }

    public function test_name_migration_postgres_down_compiles_drop_index_not_constraint(): void
    {
        $connection = new \Illuminate\Database\PostgresConnection(fn () => throw new \RuntimeException('No database connection is allowed'), 'compile-only', '', ['driver' => 'pgsql']);
        $connection->useDefaultSchemaGrammar();
        $original = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $schema = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
        $schema->shouldReceive('hasTable')->with('beam_ux_entries')->once()->andReturnTrue();
        $schema->shouldReceive('hasColumn')->with('beam_ux_entries', 'component_name')->once()->andReturnTrue();
        $schema->shouldReceive('getConnection')->once()->andReturn($connection);
        $sql = [];
        $schema->shouldReceive('table')->once()->andReturnUsing(function ($table, $callback) use ($connection, &$sql) {
            $blueprint = new \Illuminate\Database\Schema\Blueprint($connection, $table, $callback);
            $sql = $blueprint->toSql();
        });
        \Illuminate\Support\Facades\Schema::swap($schema);
        try {
            (require dirname(__DIR__).'/database/migrations/shared/add_component_names_to_beam_ux_entries_table.php.stub')->down();
        } finally {
            \Illuminate\Support\Facades\Schema::swap($original);
        }
        $this->assertContains('drop index "beam_ux_component_name_unique"', $sql);
        $this->assertStringNotContainsString('drop constraint', implode(' ', $sql));
    }
}

class AuthoredComponentReviewTestCreatePolicy
{
    public function create($actor = null): bool
    {
        return true;
    }
}
