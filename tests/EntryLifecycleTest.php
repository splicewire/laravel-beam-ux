<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Revisions\RevisionRecorder;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Lifecycle\EntryDuplicator;
use Splicewire\Beam\Ux\Lifecycle\EntryPromoter;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\Fixtures\FakeEntitlementResolver;
use Splicewire\Beam\Ux\Tests\Fixtures\Tag as FixtureTag;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Ticket 06 — duplicate/soft-delete/promote-to-central, the entry lifecycle a Frame `RowActions`
 * component drives. The component itself (a standalone, unit-tested piece) lives in the separate
 * `@splicewire/beam-ux` npm package — no host in this repo's reach mounts a live `ListShell` for
 * `BeamUxEntry` today, so nothing here exercises HTTP/UI; every assertion is against the PHP
 * mechanisms directly, which is what the ticket's acceptance criteria are actually written against.
 */
class EntryLifecycleTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables('testing');
        $this->app->singleton(EntitlementResolver::class, fn () => new FakeEntitlementResolver);
        $this->app->singleton(FakeEntitlementResolver::class, fn ($app) => $app->make(EntitlementResolver::class));

        // The default WriteGate delegates to the Laravel gate (BeamUxEntryTest's precedent).
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);

        // HasFacets' HasTags concern hooks a `deleted` listener unconditionally (detaches tags);
        // host-bind the concrete tag model + its pivot table so a soft-delete doesn't 500 on a null
        // config('beam.taxonomy.models.tag') (TaxonomyFacetsTest's precedent).
        config()->set('beam.taxonomy.models.tag', FixtureTag::class);
    }

    // ── EntryDuplicator ─────────────────────────────────────────────────────────────────────

    public function test_duplicate_copies_the_envelope_and_body_and_auto_suffixes_the_slug(): void
    {
        $source = $this->makeEntry(['slug' => 'hero', 'namespace' => 'kit', 'realm' => 'site']);
        $this->writeBody($source, ['heading' => 'Hi']);

        $copy = $this->app->make(EntryDuplicator::class)->duplicate($source->fresh());

        $this->assertSame('hero-copy', $copy->slug);
        $this->assertSame('kit', $copy->namespace);
        $this->assertSame('site', $copy->realm);
        $this->assertSame($source->type->value, $copy->type->value);
        $this->assertNotSame($source->particle_id, $copy->particle_id);
        $this->assertSame(['heading' => 'Hi'], $copy->particle->payload);
    }

    public function test_duplicate_increments_the_suffix_on_repeated_collision(): void
    {
        $source = $this->makeEntry(['slug' => 'hero', 'namespace' => 'kit']);
        $duplicator = $this->app->make(EntryDuplicator::class);

        $first = $duplicator->duplicate($source->fresh());
        $second = $duplicator->duplicate($source->fresh());

        $this->assertSame('hero-copy', $first->slug);
        $this->assertSame('hero-copy-2', $second->slug);
    }

    public function test_duplicate_does_not_copy_segment_so_it_never_collides_with_the_sources_url(): void
    {
        $root = BeamUxEntry::rootFor('site');
        $source = $this->makeEntry([
            'slug' => 'hero', 'namespace' => 'kit', 'realm' => 'site',
            'parent_id' => $root->id, 'segment' => '/hero',
        ]);

        $copy = $this->app->make(EntryDuplicator::class)->duplicate($source->fresh());

        $this->assertNull($copy->segment);
        $this->assertSame($source->parent_id, $copy->parent_id);

        $resolver = $this->app->make(UrlResolver::class);
        $this->assertSame('/hero', $resolver->resolve($source->fresh()));
        // The copy no longer contributes its own path segment — it inherits the parent's URL
        // (root, here) rather than silently shadowing the source's route.
        $this->assertNotSame('/hero', $resolver->resolve($copy));
    }

    public function test_duplicate_may_reuse_a_soft_deleted_entrys_freed_slug(): void
    {
        $source = $this->makeEntry(['slug' => 'hero', 'namespace' => 'kit']);
        $duplicator = $this->app->make(EntryDuplicator::class);

        $first = $duplicator->duplicate($source->fresh());
        $first->delete();

        // The freed slug (not -copy-2) is reused now that the first copy is soft-deleted.
        $second = $duplicator->duplicate($source->fresh());

        $this->assertSame('hero-copy', $second->slug);
    }

    // ── Soft delete / restore ───────────────────────────────────────────────────────────────

    public function test_deleting_an_entry_soft_deletes_it_and_records_a_revision(): void
    {
        $entry = $this->makeEntry(['slug' => 'hero']);

        $entry->delete();

        $this->assertSoftDeleted('beam_ux_entries', ['id' => $entry->id]);
        $this->assertFalse(BeamUxEntry::query()->whereKey($entry->id)->exists());
        $this->assertTrue(BeamUxEntry::withTrashed()->whereKey($entry->id)->exists());

        $history = $this->app->make(RevisionRecorder::class)->history($entry);
        $this->assertSame('deleted', $history[0]->cause);
    }

    public function test_restoring_an_entry_clears_deleted_at_and_records_a_revision(): void
    {
        $entry = $this->makeEntry(['slug' => 'hero']);
        $entry->delete();

        $entry->restore();

        $this->assertTrue(BeamUxEntry::query()->whereKey($entry->id)->exists());

        $history = $this->app->make(RevisionRecorder::class)->history($entry);
        $this->assertSame('restored', $history[0]->cause);
        $this->assertSame('deleted', $history[1]->cause);
    }

    /**
     * Ticket 06 explicitly asks to "confirm RevisionRecorder::revert() still works unmodified on a
     * soft-deleted row... verify the scope behavior explicitly." It does NOT: `resolveSubject()` calls
     * `$model->newQuery()->findOrFail()`, and `newQuery()` applies the SoftDeletingScope, so a revert
     * targeting a currently-soft-deleted entry 404s. This is a real gap in `RevisionRecorder` itself
     * (`splicewire/laravel-beam`, a package outside this ticket's `laravel-beam-ux`-only scope to fix)
     * — documented here as a discovered limitation, not silently left unverified.
     */
    public function test_revision_recorder_revert_currently_throws_on_a_soft_deleted_subject_known_gap(): void
    {
        $entry = $this->makeEntry(['slug' => 'hero', 'title' => 'Original']);
        $recorder = $this->app->make(RevisionRecorder::class);
        $recorder->record($entry, ['title' => 'Original'], ['title' => 'Changed'], 'updated');
        $entry->delete();

        $history = $recorder->history($entry);
        $updateEntry = collect($history)->firstWhere('cause', 'updated');

        $this->expectException(ModelNotFoundException::class);
        $recorder->revert($updateEntry);
    }

    // ── EntryPromoter ───────────────────────────────────────────────────────────────────────

    public function test_promote_denies_an_actor_without_the_realm_grant(): void
    {
        $entry = $this->makeEntry(['slug' => 'hero', 'realm' => 'site']);
        $this->app->make(FakeEntitlementResolver::class)->keys = [];

        $this->expectException(AuthorizationException::class);
        $this->app->make(EntryPromoter::class)->promote($entry, actor: (object) []);
    }

    public function test_promote_writes_the_tenant_payload_into_a_fresh_central_row_for_a_granted_actor(): void
    {
        $this->createTables('central');

        $entry = $this->makeEntry(['slug' => 'hero', 'namespace' => 'kit', 'realm' => 'site']);
        $this->writeBody($entry, ['heading' => 'Promoted']);
        $this->app->make(FakeEntitlementResolver::class)->keys = ['ux.site.author'];

        $central = $this->app->make(EntryPromoter::class)->promote($entry->fresh(), actor: (object) []);

        $this->assertSame('central', $central->getConnectionName());
        $this->assertSame('hero', $central->slug);
        $this->assertSame('kit', $central->namespace);
        $this->assertSame(BeamUxEntry::RESIDENCY_CONTEXT_FOLLOWING, $central->residency_mode);
        $this->assertNull($central->parent_id);

        $particle = BeamParticle::on('central')->find($central->particle_id);
        $this->assertSame(['heading' => 'Promoted'], $particle->payload);
    }

    public function test_promote_is_idempotent_by_namespace_and_slug_overwriting_the_prior_central_row(): void
    {
        $this->createTables('central');

        $entry = $this->makeEntry(['slug' => 'hero', 'namespace' => 'kit']);
        $this->writeBody($entry, ['heading' => 'First']);
        $this->app->make(FakeEntitlementResolver::class)->keys = ['ux.site.author'];
        $promoter = $this->app->make(EntryPromoter::class);

        $first = $promoter->promote($entry->fresh(), actor: (object) []);

        $this->writeBody($entry->fresh(), ['heading' => 'Second']);
        $second = $promoter->promote($entry->fresh(), actor: (object) []);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BeamUxEntry::on('central')->count());

        $particle = BeamParticle::on('central')->find($second->particle_id);
        $this->assertSame(['heading' => 'Second'], $particle->payload);
    }

    public function test_promote_accepts_an_explicit_payload_instead_of_reading_the_live_row(): void
    {
        $this->createTables('central');

        $entry = $this->makeEntry(['slug' => 'hero', 'namespace' => 'kit']);
        $this->writeBody($entry, ['heading' => 'Live']);
        $this->app->make(FakeEntitlementResolver::class)->keys = ['ux.site.author'];

        $central = $this->app->make(EntryPromoter::class)
            ->promote($entry->fresh(), actor: (object) [], payload: ['heading' => 'From a past revision']);

        $particle = BeamParticle::on('central')->find($central->particle_id);
        $this->assertSame(['heading' => 'From a past revision'], $particle->payload);
    }

    // ── unique-index reshape (against the REAL migration stub, not an inline test schema) ─────

    public function test_the_shipped_migration_lets_a_deleted_entrys_slug_be_reused(): void
    {
        Schema::dropIfExists('beam_ux_entries');

        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();

        $columns = Schema::getColumnListing('beam_ux_entries');
        $this->assertContains('deleted_at', $columns);

        BeamUxEntry::create(['slug' => 'hero', 'namespace' => 'kit', 'type' => UxType::Page]);
        BeamUxEntry::query()->where('slug', 'hero')->first()->delete();

        // No unique-constraint violation: the partial index excludes the soft-deleted row.
        $reused = BeamUxEntry::create(['slug' => 'hero', 'namespace' => 'kit', 'type' => UxType::Page]);
        $this->assertNotNull($reused->id);
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    private function makeEntry(array $attributes = []): BeamUxEntry
    {
        $entry = BeamUxEntry::create(array_merge([
            'slug' => 'entry',
            'namespace' => 'kit',
            'type' => UxType::Page,
            'realm' => 'site',
        ], $attributes));

        $this->writeBody($entry, []);

        return $entry->fresh();
    }

    private function writeBody(BeamUxEntry $entry, array $payload): void
    {
        $connection = $entry->getConnectionName() ?? 'testing';

        $particle = new BeamParticle;
        $particle->setConnection($connection);
        $particle->payload = $payload;
        $particle->save();

        $entry->particle_id = $particle->id;
        $entry->saveQuietly();
    }

    private function createTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        if ($schema->hasTable('beam_ux_entries')) {
            return;
        }

        $schema->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false)->index();
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
            $table->timestamps();
            $table->softDeletes();
        });

        // Mirrors the shipped migration's partial unique index (sqlite here) — EntryDuplicator's
        // uniqueSlug() collision check is only meaningful to test against the SAME constraint shape
        // production runs under; a plain (non-partial) unique index would make a freed-slug reuse
        // fail at the DB layer regardless of the app-level check being correct.
        DB::connection($connection)->statement(
            'create unique index beam_ux_entries_namespace_slug_active_unique '
            .'on beam_ux_entries (namespace, slug) where deleted_at is null'
        );

        $schema->create(Beam::table('particles'), function (Blueprint $table) {
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

        if ($connection === 'testing') {
            $schema->create('activity_log', function (Blueprint $table) {
                $table->id();
                $table->string('log_name')->nullable()->index();
                $table->text('description');
                $table->nullableUuidMorphs('subject', 'subject');
                $table->string('event')->nullable();
                $table->nullableUuidMorphs('causer', 'causer');
                $table->json('attribute_changes')->nullable();
                $table->json('properties')->nullable();
                $table->timestamps();
            });

            // HasTags' unconditional `deleted` listener queries these (see setUp's config bind).
            $schema->create('tags', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 512);
                $table->string('slug', 512);
                $table->string('type')->nullable();
                $table->timestamps();
            });

            $schema->create('taggables', function (Blueprint $table) {
                $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
                $table->uuid('taggable_id');
                $table->string('taggable_type');
                $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
            });
        }
    }
}
