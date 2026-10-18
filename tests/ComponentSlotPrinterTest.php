<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Codec\JsonDocPrinter;

class ComponentSlotPrinterTest extends TestCase
{
    private function node(string $name, array $children = [], array $props = []): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'dynamic' => false, 'props' => $props, 'children' => $children];
    }

    public function test_named_slots_and_slot_fallback_print_as_jsx_values(): void
    {
        $body = [$this->node('Card', [$this->node('Slot', [$this->node('img')], [['name' => 'name', 'kind' => 'string', 'value' => 'media']])])];
        $body[0]['slots'] = ['media' => [$this->node('img')]];
        $source = JsonDocPrinter::print($body);
        $this->assertStringContainsString('media={<img />}', $source);
        $this->assertStringContainsString('{media ?? <img />}', $source);
        $this->assertNotContains('Slot', JsonDocPrinter::componentNames($body));
    }

    public function test_prop_text_prints_as_reference_and_module_reads_its_input(): void
    {
        $body = [$this->node('h3', [['kind' => 'text', 'value' => '', 'prop' => 'title']], [['name' => 'title', 'kind' => 'prop', 'value' => 'title']])];
        $this->assertStringContainsString('{title}', JsonDocPrinter::print($body));
        $this->assertStringContainsString('{componentProps.title}', JsonDocPrinter::printModule($body));
    }
}
