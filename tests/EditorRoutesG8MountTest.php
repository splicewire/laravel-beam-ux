<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Support\EditorRoutes;

/**
 * particle-editor G8 (ruled yes, behind site.edit on the tenant): EditorRoutes::mount now mounts the
 * Entry's four declared-but-unmounted operations. This file pins three of them — workflow, transition,
 * save-blocks — as HTTP routes under the entry group, each gated by the `ux.author` entitlement its
 * declaration carries. upload-image is conditional on EntryMedia::available() and is proven mounted in
 * {@see EditorRoutesG8UploadImageMountTest}; here it is asserted ABSENT (media unavailable in this testbench).
 *
 * RED before the EditorRoutes op-list change: none of the four routes is mounted, so every
 * `assertContains(...)` here fails. Each op's own success/validation logic is covered by its unit test
 * ({@see EntryWorkflowOpsTest}, {@see EntryBodyOpsTest}); this file's subject is the MOUNT and the GATE.
 */
class EditorRoutesG8MountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
            $table->string('type')->default('page');
            $table->string('format')->default('tsx');
            $table->string('realm')->default('site');
            $table->string('residency_mode')->default('context-following');
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        // The ops declare ability `ux.author` with abilityModel:false → the subject-free entitlement plane.
        Gate::define('entitlement:ux.author', fn (?User $user = null) => $user instanceof G8Author);
        EditorRoutes::mount();
    }

    /** @return list<string> */
    private function routeUris(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->values()->all();
    }

    /** Hit a mounted entry op by its REAL method (reads register GET, writes POST — derive, don't guess). */
    private function hitOp(string $id, string $op): TestResponse
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === "beam-ux-entries/{id}/{$op}");
        $this->assertNotNull($route, "route beam-ux-entries/{id}/{$op} is not mounted");
        $method = in_array('GET', $route->methods(), true) ? 'GET' : 'POST';

        return $this->json($method, "/beam-ux-entries/{$id}/{$op}");
    }

    public function test_workflow_op_mounts_under_the_entry_group_and_is_gated_by_ux_author(): void
    {
        $this->assertContains('beam-ux-entries/{id}/workflow', $this->routeUris());

        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page']);

        // An author reaches it: the Read op returns its typed WorkflowProjectionData (null for an unmanaged entry).
        $this->actingAs(new G8Author);
        $this->hitOp($entry->id, 'workflow')->assertOk();

        // Without the entitlement, the gate refuses at the boundary.
        $this->actingAs(new User);
        $this->hitOp($entry->id, 'workflow')->assertForbidden();
    }

    public function test_transition_op_mounts_under_the_entry_group_and_is_gated_by_ux_author(): void
    {
        $this->assertContains('beam-ux-entries/{id}/transition', $this->routeUris());

        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page']);

        // An author passes the gate and reaches the op; an empty body fails its typed input validation (422),
        // which proves the route reaches EntryWorkflowTransitionOp rather than 403-ing or 404-ing.
        $this->actingAs(new G8Author);
        $this->hitOp($entry->id, 'transition')->assertStatus(422);

        $this->actingAs(new User);
        $this->hitOp($entry->id, 'transition')->assertForbidden();
    }

    public function test_save_blocks_op_mounts_under_the_entry_group_and_is_gated_by_ux_author(): void
    {
        $this->assertContains('beam-ux-entries/{id}/save-blocks', $this->routeUris());

        // A default (tsx/source) entry is not Blocks-kind, so the op's own guard refuses with a typed 422
        // (BlocksKindRequired) — reached only after the ux.author gate admitted the author.
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx']);

        $this->actingAs(new G8Author);
        $this->hitOp($entry->id, 'save-blocks')->assertStatus(422);

        $this->actingAs(new User);
        $this->hitOp($entry->id, 'save-blocks')->assertForbidden();
    }

    public function test_upload_image_is_absent_without_entry_media(): void
    {
        // The conditional op is mounted only when EntryMedia::available(); this testbench registers no
        // BeamMediaServiceProvider, so it must stay unmounted. Its mounted form is proven in
        // EditorRoutesG8UploadImageMountTest (media provider registered).
        $this->assertNotContains('beam-ux-entries/{id}/upload-image', $this->routeUris());
    }
}

class G8Author extends User
{
    protected $table = 'users';
}
