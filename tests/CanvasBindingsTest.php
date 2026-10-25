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

    public function test_typed_post_route_is_read_only_and_forbidden_for_an_unrelated_author(): void
    {
        \Splicewire\Beam\Facades\Particle::ops('canvas-entries', 'beam-ux-entry', ['canvas-bindings']);
        $entry = $this->entry();
        $other = $this->entry('unrelated');
        $this->actingAs(new GenericUser(['id' => 'author', 'author' => true]));
        $this->postJson('/canvas-entries/'.$entry->id.'/canvas-bindings', ['body' => []])->assertOk()->assertJsonPath('data.recordPage', false);
        $this->postJson('/canvas-entries/'.$other->id.'/canvas-bindings', ['body' => []])->assertForbidden();
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
