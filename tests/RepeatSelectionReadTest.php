<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Ux\Bindings\BindingResolver;

/** Real saved Repeat reader and Collection/Public Read gates on the existing isolated fixture. */
class RepeatSelectionReadTest extends CanvasBindingsRecordFixture
{
    public function test_selection_keeps_public_read_and_collection_boundaries_and_explicit_empty(): void
    {
        $visible = '019a3456-7890-7000-8000-000000000001';
        $hidden = '019a3456-7890-7000-8000-000000000002';
        DB::table('beam_particles')->where('id', 'hello')->update(['id' => $visible]);
        DB::table('beam_particles')->where('id', 'hidden')->update(['id' => $hidden]);
        $entry = $this->page();
        $resolver = app(BindingResolver::class);
        $method = new \ReflectionMethod($resolver, 'records');
        $node = ['kind' => 'block', 'id' => 'saved', 'repeat' => ['collection' => 'posts', 'query' => ['ids' => [$hidden, $visible]]],
            'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title']]];
        $visitor = Request::create('/posts?ids=ignored');
        $visitor->setUserResolver(static fn () => null);
        $before = DB::table('beam_particles')->orderBy('id')->get()->toJson();
        $result = $method->invoke($resolver, $node, $entry, $visitor, 'site');
        self::assertCount(1, $result->records);
        self::assertSame('Record hello', $result->records[0]['fields']['title']);
        self::assertArrayNotHasKey('secret', $result->records[0]['fields']);
        $node['repeat']['query']['ids'] = [];
        self::assertSame([], $method->invoke($resolver, $node, $entry, $visitor, 'site')->records);
        unset($node['repeat']['query']['ids']);
        self::assertCount(1, $method->invoke($resolver, $node, $entry, $visitor, 'site')->records);
        self::assertSame($before, DB::table('beam_particles')->orderBy('id')->get()->toJson());
        self::assertSame('ignored', $visitor->query('ids'));
    }
}
