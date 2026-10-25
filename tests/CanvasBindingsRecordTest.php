<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Bindings\CanvasBindings;
use Splicewire\Beam\Ux\Bindings\CollectionSource;
use Splicewire\Beam\Ux\Bindings\ProfileCollections;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class CanvasBindingsRecordTest extends CanvasBindingsRecordFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('entitlement:ux.author', fn ($actor) => true);
        Gate::policy(BeamUxEntry::class, CanvasBindingsRecordPolicy::class);
    }

    private function request(): Request
    {
        $actor = new GenericUser(['id' => 'author']);
        $request = Request::create('/canvas-bindings', 'POST');
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    public function test_real_record_projection_and_selection_never_write_or_disclose_unreferenced_fields(): void
    {
        $page = $this->page();
        $request = $this->request();
        $before = DB::table('beam_ux_entries')->orderBy('id')->get()->toJson();
        $body = [['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [],
            'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title']]]];
        $result = app(CanvasBindings::class)->resolve($page, $request, $body, 'hello');
        $this->assertSame('hello', $result->selectedValue);
        $this->assertSame('Record hello', $result->record['fields']['title']);
        $this->assertArrayNotHasKey('secret', $result->record['fields']);
        $this->assertCount(2, $result->choices);
        $hidden = app(CanvasBindings::class)->resolve($page, $request, $body, 'hidden');
        $this->assertSame('Record hidden', $hidden->record['fields']['title']);
        $guest = Request::create('/posts/hidden');
        $guest->setUserResolver(fn () => null);
        $this->assertNull(app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->resolve($page, 'hidden', $guest, 'site'));
        $this->assertTrue($result->recordPage);
        $missing = app(CanvasBindings::class)->resolve($page, $request, $body, 'does-not-exist');
        $this->assertNull($missing->record);
        $this->assertNull($missing->selectedValue);
        $this->assertSame($before, DB::table('beam_ux_entries')->orderBy('id')->get()->toJson());
    }

    public function test_picker_caps_the_raw_read_window_even_when_every_slug_is_invalid(): void
    {
        $page = $this->page();
        DB::table('beam_particles')->delete();
        for ($i = 0; $i < 90; $i++) {
            DB::table('beam_particles')->insert(['id' => 'invalid-'.$i, 'schema_ref' => 'https://example.test/posts/1', 'schema_id' => 'https://example.test/posts/1',
                'payload' => json_encode(['slug' => 'invalid / '.$i, 'title' => 'Invalid', 'visible' => true])]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(CanvasBindings::class)->resolve($page, $this->request(), [], null);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $result->choices);
        $this->assertNull($result->record);
        $windows = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from "beam_particles"') && str_contains($q['query'], 'limit')));
        $this->assertCount(1, $windows);
        $this->assertStringContainsString('limit 26', $windows[0]['query']);
    }

    public function test_public_read_warning_uses_collection_declaration_and_child_pages_remain_unknown(): void
    {
        $profiles = app(ProfileCollections::class);
        $this->assertTrue($profiles->publicRead(new CollectionSource('posts', 'records', ['collection' => 'https://example.test/posts'])));
        $this->assertNull($profiles->publicRead(CollectionSource::childPages('children', 'some-parent')));
        $registry = app(\Splicewire\Beam\Particle\ParticleResourceRegistry::class);
        $private = clone $registry->get('records');
        $private->key = 'private-records';
        $private->publicRead = null;
        $registry->register($private);
        $this->assertFalse($profiles->publicRead(new CollectionSource('private', 'private-records')));
    }
}

class CanvasBindingsRecordPolicy
{
    public function update($actor, BeamUxEntry $entry): bool
    {
        return true;
    }
}
