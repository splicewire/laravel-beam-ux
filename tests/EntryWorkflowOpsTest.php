<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowShowOp;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\TransitionResult;
use Splicewire\Beam\Workflows\Data\WorkflowProjectionData;
use Splicewire\Beam\Workflows\Data\WorkflowTransitionAttemptData;

/**
 * `EntryWorkflowShowOp`/`EntryWorkflowTransitionOp` (operator-surface-prototypes, Direction B — the
 * entry-sheet Workflow tab, the first WRITE particle in this effort). Calls `handle()`/`respond()`
 * directly rather than through `ParticleOperationController` — the same lighter-weight tier
 * `MirrorStatusRowDataTest`/`SitemapHealthRowDataTest` used for `project()`, proving the op's own logic
 * rather than re-testing the generic controller plumbing. Table/binding setup mirrors
 * `EntryWorkflowTest`'s own fixture exactly (same S6 columns + definition-store tables + a no-op
 * `AwaitingStore` so the always-registered clear-on-transition listener has nothing to reach for).
 */
class EntryWorkflowOpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $this->app->bind(AwaitingStore::class, fn () => new class implements AwaitingStore
        {
            public function stamp(Model $subject, string $place, string $principal, ?string $parentType = null, ?string $parentId = null): void {}

            public function clearForPlaces(Model $subject, array $places): void {}

            public function clearForSubject(Model $subject): void {}
        });
    }

    public function test_show_returns_null_for_an_unmanaged_entry(): void
    {
        $component = BeamUxEntry::create(['slug' => 'hero', 'type' => UxType::Component, 'segment' => '/hero']);

        $result = EntryWorkflowShowOp::handle($component, new Request, actor: null);

        $this->assertNull($result);
    }

    public function test_show_returns_the_current_projection_for_a_bound_page(): void
    {
        $this->bindPageWorkflow();
        $page = BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);

        $result = EntryWorkflowShowOp::handle($page, new Request, actor: null);

        $this->assertInstanceOf(WorkflowProjectionData::class, $result);
        $this->assertSame('draft', $result->current);
        $this->assertContains('publish', $result->available);
    }

    public function test_transition_applies_and_respond_carries_the_refreshed_projection(): void
    {
        $this->bindPageWorkflow();
        $page = BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);

        $request = Request::create('/beam-ux-entries/'.$page->id.'/op/transition', 'POST', ['transition' => 'publish']);
        $payload = EntryWorkflowTransitionOp::handle($page, $request, actor: null);

        $this->assertInstanceOf(TransitionResult::class, $payload);
        $this->assertTrue($payload->applied);

        $attempt = EntryWorkflowTransitionOp::respond($payload, $page);

        $this->assertInstanceOf(WorkflowTransitionAttemptData::class, $attempt);
        $this->assertTrue($attempt->applied);
        $this->assertSame([], $attempt->blockers);
        $this->assertSame('published', $attempt->projection->current);
        $this->assertSame(BeamUxEntry::MARKING_PUBLISHED, $page->fresh()->workflow_marking);
    }

    public function test_an_illegal_transition_degrades_with_blockers_instead_of_throwing(): void
    {
        $this->bindPageWorkflow();
        $page = BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);

        // `unpublish` is not legal from `draft` — the initial place.
        $request = Request::create('/beam-ux-entries/'.$page->id.'/op/transition', 'POST', ['transition' => 'unpublish']);
        $payload = EntryWorkflowTransitionOp::handle($page, $request, actor: null);
        $attempt = EntryWorkflowTransitionOp::respond($payload, $page);

        $this->assertFalse($attempt->applied);
        $this->assertNotEmpty($attempt->blockers);
        // Nothing persisted — still the initial place.
        $this->assertSame(EntryPublishLifecycle::DRAFT, $page->fresh()->workflow_marking ?? EntryPublishLifecycle::DRAFT);
    }

    public function test_transitioning_an_unmanaged_entry_degrades_with_a_null_projection(): void
    {
        $component = BeamUxEntry::create(['slug' => 'hero', 'type' => UxType::Component, 'segment' => '/hero']);

        $request = Request::create('/beam-ux-entries/'.$component->id.'/op/transition', 'POST', ['transition' => 'publish']);
        $payload = EntryWorkflowTransitionOp::handle($component, $request, actor: null);
        $attempt = EntryWorkflowTransitionOp::respond($payload, $component);

        $this->assertFalse($attempt->applied);
        $this->assertNull($attempt->projection);
    }

    private function bindPageWorkflow(): void
    {
        $this->app->make(WorkflowBindingRegistry::class)
            ->bind(UxType::Page->value, EntryPublishLifecycle::DEFINITION);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
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
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create('workflow_definition_lineages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('workflow_definition_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lineage_id');
            $table->unsignedInteger('version');
            $table->json('blueprint');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['lineage_id', 'version']);
            $table->index(['lineage_id', 'is_active']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
}
