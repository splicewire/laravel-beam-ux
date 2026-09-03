<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\Fixtures\Tag as FixtureTag;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beam-docs-satellite 66 — `EntryArtifactStore::forget()` was public, correct, and had **zero callers**
 * anywhere in the package or the estate, so every entry ever hard-deleted left its artifact directory
 * behind forever (3345 directories against 4 live entries at `~/Herd/splicewire-app`, 2026-09-03).
 *
 * The two halves are tested apart because they fail apart: the hook covers everything deleted from now
 * on, and the command is the backfill for everything deleted before it. Each case asserts the LIVE
 * entries' directories survive in the same reading, because a reap that removed everything passes a
 * naive before/after count exactly as well as a correct one.
 */
class ArtifactReapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        Storage::fake('artifacts');
        config([
            'beam.ux.compile.disk' => 'artifacts',
            'beam.ux.compile.root' => 'beam-ux/artifacts',
            // HasFacets' HasTags concern hooks a `deleted` listener unconditionally; host-bind the
            // concrete tag model so a delete does not 500 on a null config (EntryLifecycleTest's
            // precedent).
            'beam.taxonomy.models.tag' => FixtureTag::class,
        ]);

        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    public function test_a_force_delete_drops_the_entrys_artifact_directory_and_only_its_own(): void
    {
        $doomed = $this->pageWithArtifact('doomed');
        $keeper = $this->pageWithArtifact('keeper');

        $doomed->forceDelete();

        $this->assertFalse($this->hasDirectory($doomed->getKey()), 'the force-deleted entry kept its directory');
        // Asserted in the same reading: a `deleteDirectory` aimed one level too high would clear both
        // and the first assertion alone would still pass.
        $this->assertTrue($this->hasDirectory($keeper->getKey()), 'a live entry lost its directory');
    }

    public function test_a_soft_delete_keeps_it_because_the_entry_can_be_restored(): void
    {
        $entry = $this->pageWithArtifact('parked');

        $entry->delete();

        $this->assertSoftDeleted($entry);
        $this->assertTrue($this->hasDirectory($entry->getKey()));
    }

    public function test_the_command_previews_by_default_and_deletes_nothing(): void
    {
        $live = $this->pageWithArtifact('live');
        $this->orphanDirectory('01a00000-0000-7000-8000-000000000001');

        $this->artisan('splicewire:beam:ux:reap-artifacts')->assertSuccessful();

        $this->assertTrue($this->hasDirectory('01a00000-0000-7000-8000-000000000001'));
        $this->assertTrue($this->hasDirectory($live->getKey()));
    }

    public function test_apply_reaps_the_orphans_and_leaves_every_live_entry_alone(): void
    {
        $live = $this->pageWithArtifact('live');
        $parked = $this->pageWithArtifact('parked');
        $parked->delete();
        $this->orphanDirectory('01a00000-0000-7000-8000-000000000001');
        $this->orphanDirectory('01a00000-0000-7000-8000-000000000002');

        $this->artisan('splicewire:beam:ux:reap-artifacts --apply')->assertSuccessful();

        $this->assertFalse($this->hasDirectory('01a00000-0000-7000-8000-000000000001'));
        $this->assertFalse($this->hasDirectory('01a00000-0000-7000-8000-000000000002'));
        $this->assertTrue($this->hasDirectory($live->getKey()));
        // A soft-deleted row is still a row, and `withTrashed()` is what keeps its artifact reachable
        // after a restore. Reaping it here would be the silent half of this defect, reversed.
        $this->assertTrue($this->hasDirectory($parked->getKey()));
    }

    public function test_a_clean_run_states_the_live_count_so_zero_orphans_is_not_zero_looked_at(): void
    {
        $live = $this->pageWithArtifact('live');

        $this->artisan('splicewire:beam:ux:reap-artifacts')
            ->expectsOutputToContain('nothing to reap; 1 live-entry director(ies) present.')
            ->assertSuccessful();

        $this->assertTrue($this->hasDirectory($live->getKey()));
    }

    private function pageWithArtifact(string $slug): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => $slug,
            'type' => UxType::Page->value,
            'realm' => 'site',
            'segment' => $slug,
        ]);

        Storage::disk('artifacts')->put("beam-ux/artifacts/{$entry->getKey()}/deadbeef.js", 'export default 1;');

        return $entry;
    }

    private function orphanDirectory(string $id): void
    {
        Storage::disk('artifacts')->put("beam-ux/artifacts/{$id}/deadbeef.js", 'export default 1;');
    }

    private function hasDirectory(string $id): bool
    {
        return in_array(
            "beam-ux/artifacts/{$id}",
            Storage::disk('artifacts')->directories('beam-ux/artifacts'),
            strict: true,
        );
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false);
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
            $table->string('segment')->nullable()->index();
            $table->integer('nav_order')->nullable();
            $table->json('traverse')->nullable();
            $table->json('access')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
            $table->unique(['parent_id', 'segment']);
        });

        // A soft delete records a `deleted` revision through the activity log.
        Schema::create('activity_log', function (Blueprint $table) {
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

        // HasFacets' HasTags concern hooks a `deleted` listener unconditionally, so a delete of any
        // kind touches these two. beam-taxonomy's create migrations ship in tower for the host; the
        // harness stands up the minimal shape the morph needs (TaxonomyFacetsTest's precedent).
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 512);
            $table->string('slug', 512);
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->uuid('taggable_id');
            $table->string('taggable_type');
            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });
    }
}
