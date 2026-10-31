<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Bindings\CanvasBindings;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** The picker uses a request-only projection through the existing addressed author's guarded reader. */
class HandpickedAuthorReadTest extends CanvasBindingsRecordFixture
{
    public function test_record_name_and_identity_projection_uses_the_existing_author_reader_without_writing(): void
    {
        Gate::define('entitlement:ux.author', static fn ($actor) => true);
        Gate::policy(BeamUxEntry::class, HandpickedAuthorReadPolicy::class);
        $id = '019a3456-7890-7000-8000-000000000001';
        DB::table('beam_particles')->where('id', 'hello')->update(['id' => $id]);
        $request = Request::create('/canvas-bindings', 'POST');
        $request->setUserResolver(static fn () => new GenericUser(['id' => 'author']));
        $body = [['kind' => 'block', 'id' => 'saved', 'name' => 'p', 'dynamic' => false, 'isComponent' => false, 'props' => [], 'children' => [],
            'repeat' => ['collection' => 'posts', 'query' => ['limit' => 100]],
            'when' => ['any' => [['field' => 'id', 'operator' => 'is set'], ['field' => 'fields.title', 'operator' => 'is set']]]]];
        $entry = $this->page();
        $before = DB::table('beam_particles')->orderBy('id')->get()->toJson();
        $result = app(CanvasBindings::class)->resolve($entry, $request, $body, null);
        $choices = $result->bindings['saved']->records;
        self::assertContains($id, array_column($choices, 'id'));
        $picked = array_values(array_filter($choices, static fn ($row) => $row['id'] === $id))[0];
        self::assertSame('Record hello', $picked['fields']['title']);
        self::assertArrayNotHasKey('secret', $picked['fields']);
        self::assertSame($before, DB::table('beam_particles')->orderBy('id')->get()->toJson());
        self::assertSame(100, $body[0]['repeat']['query']['limit']);
    }
}

class HandpickedAuthorReadPolicy
{
    public function update($actor, BeamUxEntry $entry): bool
    {
        return true;
    }
}
