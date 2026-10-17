<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Splicewire\Beam\Ux\Manifest\HtmlManifestProfile;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0228 d6 (EP-07a, part a) — laravel-beam-ux ships a base profile of the intrinsic HTML elements
 * with OPEN attributes. The entry shape must match Schemastud\Blockdoc's v1 manifest grammar
 * (`ManifestBuilder::node()`) so the per-realm resolver (EP-07a b) can concatenate this base's node
 * list with the host's `#[NodeType]` components, and the JS `assemblePMSchema([base, profile])` reads
 * one vocabulary.
 */
class HtmlManifestProfileTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function nodesByName(): array
    {
        return array_column(HtmlManifestProfile::manifest()['nodes'], null, 'name');
    }

    public function test_it_ships_a_v1_html_manifest_envelope(): void
    {
        $m = HtmlManifestProfile::manifest();

        $this->assertSame('html', HtmlManifestProfile::PROFILE);
        $this->assertSame('html', $m['profile']);
        $this->assertSame(1, $m['version']);
        // The doc admits the single intrinsic-HTML category; HTML nests freely within itself.
        $this->assertSame(['html'], $m['doc']['admitsChildCategories']);
        // The base profile adds no marks of its own (base marks live in Schemastud's base manifest).
        $this->assertSame([], $m['marks']);
    }

    public function test_it_lists_the_intrinsic_html_elements_by_jsx_tag(): void
    {
        $names = array_keys($this->nodesByName());

        foreach (['section', 'div', 'p', 'h1', 'span', 'a', 'button', 'ul', 'li', 'img', 'table', 'tr', 'td'] as $tag) {
            $this->assertContains($tag, $names, "HTML base profile must ship the intrinsic <$tag> element");
        }
    }

    public function test_every_entry_matches_the_v1_node_grammar_and_is_open(): void
    {
        foreach ($this->nodesByName() as $name => $node) {
            $this->assertSame(
                ['name', 'group', 'category', 'admitsChildCategories', 'admitsText', 'contentExpression', 'attrsSchema'],
                array_keys($node),
                "node <$name> must carry exactly the v1 ManifestBuilder::node() keys"
            );
            $this->assertSame('block', $node['group']);
            $this->assertSame('html', $node['category']);
            $this->assertNull($node['contentExpression']);
            // d6: open attributes — any attribute is allowed, with the stable d3 id typed.
            $this->assertTrue($node['attrsSchema']['additionalProperties'], "node <$name> must have OPEN attributes");
            $this->assertSame(['string', 'null'], $node['attrsSchema']['properties']['id']['type']);
        }
    }

    public function test_a_container_admits_html_children_and_text(): void
    {
        $div = $this->nodesByName()['div'];

        $this->assertSame(['html'], $div['admitsChildCategories']);
        $this->assertTrue($div['admitsText']);
    }

    public function test_a_void_element_is_a_childless_textless_leaf(): void
    {
        $img = $this->nodesByName()['img'];

        $this->assertSame([], $img['admitsChildCategories']);
        $this->assertFalse($img['admitsText']);
    }
}
