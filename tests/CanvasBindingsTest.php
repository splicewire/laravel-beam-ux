<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Bindings\CanvasBindings;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CanvasBindingsTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('entitlement:ux.author', fn ($actor) => $actor->author ?? false);
        Gate::policy(BeamUxEntry::class, CanvasBindingsEntryPolicy::class);
    }

    private function entry(string $slug = 'editable'): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $slug, 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
    }

    public function test_guest_non_author_and_unrelated_entry_refuse_before_any_data_read(): void
    {
        $entry = $this->entry();
        $other = $this->entry('unrelated');
        foreach ([[null, $entry], [new GenericUser(['id' => 'reader', 'author' => false]), $entry], [new GenericUser(['id' => 'author', 'author' => true]), $other]] as [$actor, $addressed]) {
            $request = Request::create('/canvas-bindings', 'POST');
            $request->setUserResolver(fn () => $actor);
            $db = $entry->getConnection();
            $db->enableQueryLog();
            $db->flushQueryLog();
            try {
                app(CanvasBindings::class)->resolve($addressed, $request, [], null);
                $this->fail('Unauthorized canvas read succeeded');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            $this->assertSame([], $db->getQueryLog());
            $db->disableQueryLog();
        }
    }

    public function test_author_empty_preview_is_read_only_and_has_no_record_selection(): void
    {
        $entry = $this->entry();
        $actor = new GenericUser(['id' => 'author', 'author' => true]);
        $request = Request::create('/canvas-bindings', 'POST');
        $request->setUserResolver(fn () => $actor);
        $before = $entry->fresh()->getAttributes();
        $db = $entry->getConnection();
        $db->enableQueryLog();
        $db->flushQueryLog();
        $result = app(CanvasBindings::class)->resolve($entry, $request, [], null);
        $queries = $db->getQueryLog();
        $db->disableQueryLog();
        $this->assertSame([], $result->bindings);
        $this->assertSame([], $result->choices);
        $this->assertNull($result->record);
        $this->assertFalse($result->recordPage);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }

    public function test_typed_post_preserves_string_props_and_named_slot_arrays_without_writes(): void
    {
        \Splicewire\Beam\Facades\Particle::ops('canvas-entries', 'beam-ux-entry', ['canvas-bindings']);
        $entry = $this->entry();
        $this->actingAs(new GenericUser(['id' => 'author', 'author' => true]));
        $slot = ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false,
            'props' => [['name' => 'title', 'kind' => 'string', 'value' => 'Slot title']],
            'children' => [['kind' => 'text', 'value' => 'Caller content']]];
        $body = [['kind' => 'block', 'name' => 'Card', 'isComponent' => true, 'dynamic' => false,
            'props' => [['name' => 'title', 'kind' => 'string', 'value' => 'Fixture title']],
            'children' => [$slot], 'slots' => ['aside' => [$slot]]]];
        $before = $entry->fresh()->getAttributes();
        $db = $entry->getConnection();
        $db->enableQueryLog();
        $db->flushQueryLog();
        $this->postJson('/canvas-entries/'.$entry->id.'/canvas-bindings', ['body' => $body])
            ->assertOk()->assertJsonPath('data.bindings', [])->assertJsonPath('data.record', null);
        $queries = $db->getQueryLog();
        $db->disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }

    public function test_page_public_uses_guest_ancestor_guards_and_reports_unknown_chains(): void
    {
        $page = $this->entry();
        $parent = $this->entry('parent');
        $page->update(['parent_id' => $parent->id]);
        $request = Request::create('/canvas-bindings', 'POST');
        $request->setUserResolver(fn () => new GenericUser(['id' => 'author', 'author' => true]));
        $read = fn () => app(CanvasBindings::class)->resolve($page->fresh(), $request, [], null)->pagePublic;
        $this->assertTrue($read());
        $page->update(['access' => ['auth']]);
        $this->assertFalse($read());
        $page->update(['access' => null]);
        $parent->update(['traverse' => ['auth']]);
        $this->assertFalse($read());
        $parent->update(['traverse' => null, 'parent_id' => $page->id]);
        $this->assertNull($read());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }

    public function test_preview_rejects_oversized_bodies_and_excess_root_repeat_reads(): void
    {
        $bodies = [
            [['kind' => 'text', 'value' => str_repeat('x', 262145)]],
            array_map(fn ($id) => ['kind' => 'block', 'id' => 'r'.$id, 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [], 'repeat' => ['collection' => 'posts']], range(1, 21)),
        ];
        foreach ($bodies as $body) {
            try {
                \Splicewire\Beam\Ux\Data\EntryCanvasBindingsInputData::validateAndCreate(['body' => $body]);
                $this->fail('Unbounded preview input accepted');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertArrayHasKey('body', $error->errors());
                $this->assertStringContainsString('preview', strtolower($error->getMessage()));
            }
        }
    }

    public function test_preview_bounds_preserve_normal_validation_and_count_root_empty_branches(): void
    {
        $node = ['kind' => 'block', 'id' => 'r', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [], 'repeat' => ['collection' => 'posts']];
        $input = \Splicewire\Beam\Ux\Data\EntryCanvasBindingsInputData::class;
        $input::assertBounds(['body' => array_fill(0, 20, $node)]);
        $this->addToAssertionCount(1);
        $node['repeat']['empty'] = array_fill(0, 20, $node);
        try {
            $input::assertBounds(['body' => [$node]]);
            $this->fail('Unbounded empty-branch reads accepted');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertStringContainsString('20 root Repeats', $error->getMessage());
        }
        foreach (['children', 'slots'] as $key) {
            try {
                $bad = $node;
                unset($bad['repeat']);
                $bad[$key] = 'invalid';
                $input::validateAndCreate(['body' => [$bad]]);
                $this->fail('Malformed children accepted');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_typed_post_route_is_read_only_and_forbidden_for_an_unrelated_author(): void
    {
        \Splicewire\Beam\Facades\Particle::ops('canvas-entries', 'beam-ux-entry', ['canvas-bindings']);
        $entry = $this->entry();
        $other = $this->entry('unrelated');
        $this->actingAs(new GenericUser(['id' => 'author', 'author' => true]));
        $this->postJson('/canvas-entries/'.$entry->id.'/canvas-bindings', ['body' => []])->assertOk()->assertJsonPath('data.recordPage', false);
        $this->postJson('/canvas-entries/'.$other->id.'/canvas-bindings', ['body' => []])->assertForbidden();
        $this->postJson('/canvas-entries/'.$other->id.'/canvas-bindings', ['body' => ['malformed' => true]])->assertForbidden();
        $this->postJson('/canvas-entries/'.$entry->id.'/canvas-bindings', ['body' => [], 'collection' => 'forged'])->assertUnprocessable();
        $this->postJson('/canvas-entries/'.$entry->id.'/canvas-bindings', ['body' => ['source' => 'forged']])->assertUnprocessable();
        $this->postJson('/canvas-entries/'.$entry->id.'/canvas-bindings', ['body' => [['kind' => 'block', 'name' => 'p']]])->assertUnprocessable();
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }
}

class CanvasBindingsEntryPolicy
{
    public function update($actor, BeamUxEntry $entry): bool
    {
        return $entry->slug === 'editable';
    }
}
