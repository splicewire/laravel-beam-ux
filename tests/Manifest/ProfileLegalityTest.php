<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Manifest\HtmlManifestProfile;
use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0228 d8 / ux-0227 d1 (EP-07a, part c, first increment) — a save checks each canvas (JsonDoc) node
 * against the realm's resolved Manifest Profile. This increment covers the unknown-name check and the
 * `x-admits-code: false` code refusal (an expression prop + an opaque node); containment and the
 * per-node attrsSchema land in the next increment. Every violation names the node by id + path.
 */
class ProfileLegalityTest extends TestCase
{
    /** @return array<string, mixed> an HTML base profile with x-admits-code set. */
    private function profile(bool $admitsCode): array
    {
        return HtmlManifestProfile::manifest() + ['x-admits-code' => $admitsCode];
    }

    private function block(string $name, ?string $id, array $children = [], array $props = []): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => false, 'props' => $props, 'children' => $children, 'dynamic' => false, 'id' => $id];
    }

    /** @return array<string, string> the 'body' violation messages, or [] if none thrown. */
    private function violations(array $body, array $profile): array
    {
        try {
            (new ProfileLegality)->assert($body, $profile);

            return [];
        } catch (ValidationException $e) {
            return $e->errors()['body'] ?? ['*' => 'thrown without a body key'];
        }
    }

    public function test_a_legal_html_body_passes(): void
    {
        $body = [$this->block('div', 'd1', [$this->block('p', 'p1', [['kind' => 'text', 'value' => 'hi']])])];

        $this->assertSame([], $this->violations($body, $this->profile(true)));
    }

    public function test_an_unknown_block_is_refused_by_id_and_path(): void
    {
        $body = [$this->block('div', 'd1', [$this->block('Marquee', 'm9', [])])];

        $v = $this->violations($body, $this->profile(true));
        $this->assertNotEmpty($v);
        $joined = implode("\n", $v);
        $this->assertStringContainsString('Marquee', $joined);
        $this->assertStringContainsString('m9', $joined);
        $this->assertStringContainsString('0.0', $joined); // path: first child of the first root node
    }

    public function test_an_opaque_node_is_refused_when_code_is_not_admitted(): void
    {
        $body = [$this->block('div', 'd1', [['kind' => 'opaque', 'reason' => 'unsupported', 'source' => '<script>steal()</script>']])];

        $this->assertSame([], $this->violations($body, $this->profile(true)), 'opaque is fine when code is admitted');

        $v = $this->violations($body, $this->profile(false));
        $this->assertNotEmpty($v);
        $this->assertStringContainsString('opaque', implode("\n", $v));
    }

    public function test_an_expression_prop_is_refused_when_code_is_not_admitted(): void
    {
        $body = [$this->block('div', 'd1', props: [['name' => 'hidden', 'kind' => 'expression', 'value' => 'user.isAdmin']])];

        $this->assertSame([], $this->violations($body, $this->profile(true)), 'an expression prop is fine when code is admitted');

        $v = $this->violations($body, $this->profile(false));
        $this->assertNotEmpty($v);
        $joined = implode("\n", $v);
        $this->assertStringContainsString('hidden', $joined);
        $this->assertStringContainsString('d1', $joined);
    }

    public function test_a_registered_component_is_allowed(): void
    {
        // A component whose JSX tag is a known profile entry is a registered component — allowed (ux-0227 d1).
        $body = [['kind' => 'block', 'name' => 'section', 'isComponent' => true, 'props' => [], 'children' => [], 'dynamic' => false, 'id' => 'c1']];

        $this->assertSame([], $this->violations($body, $this->profile(false)));
    }

    public function test_a_child_under_a_void_element_is_refused_by_containment(): void
    {
        // <img> is a leaf (admitsChildCategories []), so any child is illegal.
        $body = [$this->block('img', 'i1', [$this->block('span', 's1', [])])];

        $v = $this->violations($body, $this->profile(true));
        $joined = implode("\n", $v);
        $this->assertStringContainsString('s1', $joined);
        $this->assertStringContainsString('not admitted', $joined);
    }

    public function test_bare_text_at_the_root_is_refused(): void
    {
        $body = [['kind' => 'text', 'value' => 'loose text']];

        $v = $this->violations($body, $this->profile(true));
        $this->assertNotEmpty($v);
        $this->assertStringContainsString('text', implode("\n", $v));
    }

    public function test_a_fragment_is_a_transparent_containment_wrapper(): void
    {
        // A fragment (name null) holding a <div> at the root is legal: the div is checked against the root.
        $body = [['kind' => 'block', 'name' => null, 'isComponent' => false, 'props' => [], 'children' => [$this->block('div', 'd1', [])], 'dynamic' => false, 'id' => 'f1']];

        $this->assertSame([], $this->violations($body, $this->profile(true)));
    }
}
