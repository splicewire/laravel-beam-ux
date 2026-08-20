<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Sitemap\Tags\Url;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;
use Splicewire\Beam\Ux\Sitemap\WorkflowMarkingPublishGate;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\LifecycleService;

/**
 * beamux-entry-charter S6 — the entry as an OPTIONAL beam-workflows subject.
 *
 * Proves the two halves of the charter box:
 *
 *  1. A bound page (type=`page` bound to the publish lifecycle) transitions through its workflow, its
 *     marking persists on the `workflow_marking` column, and that flip changes what
 *     {@see EntrySitemapSource} yields — the real {@see WorkflowMarkingPublishGate} reads the marking
 *     (published ⇒ visible), replacing S4's `AlwaysPublishedGate` stub.
 *  2. An unbound component (type=`component`, no binding) has NO workflow — the engine reports it
 *     unmanaged, no state machine is forced, and it stays published by default (unmanaged ⇒ visible).
 *
 * The engine (guards, versioned definitions, activitylog Display) is the sibling package's; this test
 * only wires beam-ux's side: the entry subject + the binding + the persisted marking.
 */
class EntryWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('app.url', 'https://example.test');

        // The awaiting projection is beam-workflows-UX territory, not what S6 exercises. Bind a no-op
        // store so the always-registered clear-on-transition listener doesn't reach for the host's
        // `workflow_awaitings` table (which a beam-ux host is not required to provision).
        $this->app->bind(AwaitingStore::class, fn () => new class implements AwaitingStore
        {
            public function stamp(Model $subject, string $place, string $principal, ?string $parentType = null, ?string $parentId = null): void {}

            public function clearForPlaces(Model $subject, array $places): void {}

            public function clearForSubject(Model $subject): void {}
        });
    }

    public function test_a_bound_page_is_a_workflow_subject_and_an_unbound_component_is_not(): void
    {
        // Bind ONLY the `page` type to the shipped publish lifecycle (host decision — optional workflow).
        $this->bindPageWorkflow();

        $lifecycle = $this->app->make(LifecycleService::class);

        $page = BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);
        $component = BeamUxEntry::create(['slug' => 'hero', 'type' => UxType::Component, 'segment' => '/hero']);

        // The bound page IS managed; the unbound component is NOT (no binding ⇒ no state machine).
        $this->assertTrue($lifecycle->manages($page), 'a bound page must be a workflow subject');
        $this->assertFalse($lifecycle->manages($component), 'an unbound component must have no workflow');

        // The component's available transitions are empty — there is no graph to move it through.
        $this->assertSame([], $lifecycle->available($component));

        // The bound page can be published (the lifecycle's first legal transition from `draft`).
        $this->assertContains('publish', $lifecycle->available($page));
    }

    public function test_a_page_transition_flips_its_persisted_marking(): void
    {
        $this->bindPageWorkflow();
        $lifecycle = $this->app->make(LifecycleService::class);

        $page = BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);

        // Starts at the lifecycle's initial place (draft) — not yet the published marking.
        $this->assertNotSame(BeamUxEntry::MARKING_PUBLISHED, $page->fresh()->workflow_marking);

        $result = $lifecycle->transition($page, 'publish');

        $this->assertTrue($result->applied, 'the publish transition must apply');
        // The marking persists on the entry row.
        $this->assertSame(BeamUxEntry::MARKING_PUBLISHED, $page->fresh()->workflow_marking);

        // And it can transition back out of published.
        $lifecycle->transition($page, 'unpublish');
        $this->assertSame(EntryPublishLifecycle::DRAFT, $page->fresh()->workflow_marking);
    }

    public function test_the_published_marking_gates_sitemap_visibility(): void
    {
        $this->bindPageWorkflow();
        $lifecycle = $this->app->make(LifecycleService::class);

        $page = BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);

        // The real S6 gate is bound by default — assert it, then drive visibility off the marking.
        $this->assertInstanceOf(
            WorkflowMarkingPublishGate::class,
            $this->app->make(EntryPublishGate::class),
        );

        $source = $this->app->make(EntrySitemapSource::class);

        // Draft (unpublished) → gated out of the sitemap.
        $this->assertNotContains('https://example.test/about', $this->urlsFrom($source));

        // Publish → the marking flips to `published` → the page appears.
        $lifecycle->transition($page, 'publish');
        $this->assertContains('https://example.test/about', $this->urlsFrom($source));

        // Unpublish → back out of the sitemap.
        $lifecycle->transition($page, 'unpublish');
        $this->assertNotContains('https://example.test/about', $this->urlsFrom($source));
    }

    public function test_an_unbound_page_stays_published_by_default(): void
    {
        // No binding registered at all → the entry is unmanaged ⇒ visible (the optional-workflow
        // fallback: wiring S6 never hides content that had no workflow).
        BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page, 'segment' => '/legacy']);

        $urls = $this->urlsFrom($this->app->make(EntrySitemapSource::class));

        $this->assertContains('https://example.test/legacy', $urls);
    }

    private function bindPageWorkflow(): void
    {
        $this->app->make(WorkflowBindingRegistry::class)
            ->bind(UxType::Page->value, EntryPublishLifecycle::DEFINITION);
    }

    /**
     * @return list<string>
     */
    private function urlsFrom(EntrySitemapSource $source): array
    {
        return array_map(fn (Url $u) => $u->url, iterator_to_array($source->urls(), false));
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
            // S6 workflow aspect columns.
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
        // The beam-workflows definition-store tables. Left EMPTY: the entry publish lifecycle resolves
        // through the code-registered blueprint (the package's back-compat path), so no DB lineage is
        // needed — but `LifecycleService::resolve()` probes `activeVersion()` before falling through to
        // the blueprint registry, so the tables must exist for that probe to return null cleanly.
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

        // The spatie/activitylog projection the workflows Display seam writes to on each transition
        // (versioned definitions + activitylog Display come from the package free — S6). Present so a
        // real transition's Display event has somewhere to land.
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
