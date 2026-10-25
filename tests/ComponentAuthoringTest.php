<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Components\ComponentAuthoring;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class ComponentAuthoringTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('ux.site.author', fn ($actor) => $actor->author ?? false);
        Gate::define('entitlement:ux.author', fn ($actor) => $actor->author ?? false);
        Gate::policy(BeamUxEntry::class, ComponentAuthoringFixturePolicy::class);
        // Native duplicator also checks create on its has-a particle; this fixture declares that permission.
        Gate::policy(\Splicewire\Beam\Models\BeamParticle::class, ComponentAuthoringFixturePolicy::class);
    }

    public function test_capability_uses_addressed_update_and_returns_no_reach_for_read_only_author(): void
    {
        \Splicewire\Beam\Facades\Particle::ops('component-entries', 'beam-ux-entry', ['component-capabilities']);
        $root = BeamUxEntry::rootFor('site');
        $entry = BeamUxEntry::create(['slug' => 'card', 'type' => 'component', 'realm' => 'site', 'parent_id' => $root->id, 'format' => 'tsx', 'component_kind' => 'authored']);
        app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->write($entry, [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [], 'component' => ['name' => 'Card', 'props' => []]]]);
        $readOnly = new GenericUser(['id' => 'reader', 'author' => true, 'edit' => false]);
        $result = app(ComponentAuthoring::class)->capabilities($entry, $readOnly);
        $this->assertFalse($result->canEdit);
        $this->assertNull($result->reachEntries);
        $this->assertFalse($result->reachUnavailable);
        $author = new GenericUser(['id' => 'author', 'author' => true, 'edit' => true]);
        $result = app(ComponentAuthoring::class)->capabilities($entry, $author);
        $this->assertTrue($result->canEdit);
        $this->assertSame(0, $result->reachEntries);
        $this->assertSame(500, $result->reachLimit);
        $this->actingAs($author);
        $this->getJson('/component-entries/'.$entry->id.'/component-capabilities')->assertOk()->assertJsonPath('data.canEdit', true)->assertJsonPath('data.reachEntries', 0);
        $this->actingAs($readOnly);
        $this->getJson('/component-entries/'.$entry->id.'/component-capabilities')->assertOk()->assertJsonPath('data.canEdit', false)->assertJsonPath('data.reachEntries', null);
    }

    public function test_native_create_is_draft_only_and_uses_same_realm_root_then_duplicate_reserves_a_new_name(): void
    {
        $root = BeamUxEntry::rootFor('site');
        $context = BeamUxEntry::create(['slug' => 'page', 'type' => 'page', 'realm' => 'site', 'parent_id' => $root->id, 'format' => 'tsx']);
        $actor = new ComponentAuthoringFixtureUser(['id' => (string) \Illuminate\Support\Str::uuid(), 'author' => true, 'edit' => true]);
        $this->actingAs($actor);
        $request = \Illuminate\Http\Request::create('/create-component', 'POST');
        $request->setUserResolver(fn () => $actor);
        $body = [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [], 'component' => ['name' => 'Card', 'props' => []]]];
        $input = \Splicewire\Beam\Ux\Data\EntryComponentCreateInputData::validateAndCreate(['title' => 'Card', 'body' => $body]);
        $entry = app(ComponentAuthoring::class)->create($context, $request, $actor, $input);
        $this->assertSame('site', $entry->realm);
        $this->assertSame($root->id, $entry->parent_id);
        $this->assertSame('Card', $entry->component_name);
        $this->assertNull($entry->published_version);
        $state = app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->state($entry);
        $this->assertNotNull($state->headVersion);
        $this->assertSame($input->toArray()['body'], app(\Splicewire\Beam\Ux\Components\AuthoredComponents::class)->working($entry));
        $copy = app(ComponentAuthoring::class)->duplicate($entry, $actor);
        $this->assertNotSame($entry->id, $copy->id);
        $this->assertSame('CardCopy', $copy->component_name);
        $this->assertNull($copy->published_version);
        $this->assertSame('Card', $entry->fresh()->component_name);
        $this->assertNull($context->fresh()->particle_id);
    }

    public function test_denied_actor_gets_no_capability_or_creation_side_effects(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'page', 'type' => 'page', 'realm' => 'site', 'format' => 'tsx']);
        $before = BeamUxEntry::count();
        try {
            app(ComponentAuthoring::class)->capabilities($entry, new GenericUser(['id' => 'reader', 'author' => false]));
            $this->fail('Non-author read succeeded');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame($before, BeamUxEntry::count());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
    }

    public function test_native_routes_refuse_before_validation_and_never_create_for_a_denied_actor(): void
    {
        \Splicewire\Beam\Facades\Particle::ops('component-entries', 'beam-ux-entry', ['component-capabilities', 'create-component', 'duplicate-component']);
        $entry = BeamUxEntry::create(['slug' => 'page', 'type' => 'page', 'realm' => 'site', 'format' => 'tsx']);
        $before = BeamUxEntry::count();
        $this->actingAs(new GenericUser(['id' => 'non-author', 'author' => false, 'edit' => true]));
        $body = [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [], 'component' => ['name' => 'Card', 'props' => []]]];
        $this->postJson('/component-entries/'.$entry->id.'/create-component', ['title' => 'Card', 'body' => $body])->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 'unrelated-author', 'author' => true, 'edit' => false]));
        $this->postJson('/component-entries/'.$entry->id.'/create-component', ['body' => 'invalid'])->assertForbidden();
        $this->postJson('/component-entries/'.$entry->id.'/duplicate-component', [])->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 'author', 'author' => true, 'edit' => true]));
        $this->postJson('/component-entries/'.$entry->id.'/create-component', ['title' => 'Card', 'body' => 'invalid'])->assertUnprocessable();
        $this->assertSame($before, BeamUxEntry::count());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }

    public function test_reach_over_the_bound_is_unavailable_without_loading_bodies_or_writing_state(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'card', 'type' => 'component', 'realm' => 'site', 'format' => 'tsx', 'component_kind' => 'authored']);
        $rows = [];
        for ($i = 0; $i < ComponentAuthoring::REACH_LIMIT; $i++) {
            $rows[] = ['id' => (string) \Illuminate\Support\Str::uuid(), 'slug' => 'page-'.$i, 'type' => 'page', 'realm' => 'site', 'format' => 'tsx'];
        }
        BeamUxEntry::insert($rows);
        $connection = $entry->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $result = app(ComponentAuthoring::class)->capabilities($entry, new GenericUser(['id' => 'author', 'author' => true, 'edit' => true]));
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $this->assertTrue($result->canEdit);
        $this->assertNull($result->reachEntries);
        $this->assertTrue($result->reachUnavailable);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('beam_particles', $query['query']);
            $this->assertStringNotContainsString('beam_versions', $query['query']);
            $this->assertStringStartsWith('select', strtolower($query['query']));
        }
        $this->assertSame(501, BeamUxEntry::count());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }
}

class ComponentAuthoringFixturePolicy
{
    public function update($actor, BeamUxEntry $entry): bool
    {
        return $actor->edit ?? false;
    }

    public function create($actor): bool
    {
        return $actor->edit ?? false;
    }
}

class ComponentAuthoringFixtureUser extends \Illuminate\Foundation\Auth\User
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
