<?php

namespace Splicewire\Beam\Ux\Tests\Bindings;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Ux\Bindings\DeclaredFieldRenames;

final class DeclaredFieldRenamesTest extends TestCase
{
    private function history(): array
    {
        return [
            ['$id' => 'https://tenant.test/schemas/articles/1', 'properties' => [
                'slug' => ['type' => 'string', 'x-beam-unique' => true],
                'headline' => ['type' => 'string', 'x-filter' => ['name' => 'heading'], 'x-sort' => ['name' => 'heading-order']],
                'discarded' => ['type' => 'string'],
            ]],
            ['$id' => 'https://tenant.test/schemas/articles/2', 'properties' => [
                'slug' => ['type' => 'string', 'x-beam-unique' => true],
                'title' => ['type' => 'string', 'x-migrate-from' => 'headline', 'x-filter' => ['name' => 'title-filter'], 'x-sort' => ['name' => 'title-order']],
            ]],
            ['$id' => 'https://tenant.test/schemas/articles/3', 'properties' => [
                'slug' => ['type' => 'string', 'x-beam-unique' => true],
                'name' => ['type' => 'string', 'x-migrate-from' => 'title', 'x-filter' => ['name' => 'name-filter'], 'x-sort' => ['name' => 'name-order']],
            ]],
        ];
    }

    public function test_follows_only_declared_top_level_renames_across_every_version(): void
    {
        $renames = new DeclaredFieldRenames($this->history());
        self::assertSame('name', $renames->field('headline'));
        self::assertSame('name', $renames->field('title'));
        self::assertSame('name', $renames->field('name'));
        self::assertSame('name.child', $renames->field('headline.child'));
        self::assertNull($renames->field('discarded'));
        self::assertNull($renames->field('unknown'));
        self::assertSame('slug', $renames->field('slug'));
    }

    public function test_saved_queries_follow_owner_declared_aliases_not_arbitrary_strings(): void
    {
        $renames = new DeclaredFieldRenames($this->history());
        self::assertSame(['filter' => ['name-filter' => 'headline', 'untouched' => 'title'], 'sort' => '-name-order', 'limit' => 7],
            $renames->query(['filter' => ['heading' => 'headline', 'untouched' => 'title'], 'sort' => '-heading-order', 'limit' => 7]));
        self::assertSame(['filter' => ['name-filter' => 'literal'], 'sort' => 'name-order'],
            $renames->query(['filter' => ['name-filter' => 'literal'], 'sort' => 'name-order']));
    }

    public function test_a_drop_breaks_the_chain_even_when_a_later_unrelated_field_reuses_the_name(): void
    {
        $history = $this->history();
        unset($history[1]['properties']['title']);
        self::assertNull((new DeclaredFieldRenames($history))->field('headline'));
    }

    public function test_ambiguous_declared_sources_refuse_instead_of_guessing(): void
    {
        $history = $this->history();
        $history[1]['properties']['other'] = ['type' => 'string', 'x-migrate-from' => 'headline'];
        $this->expectException(\InvalidArgumentException::class);
        (new DeclaredFieldRenames($history))->field('headline');
    }
}
