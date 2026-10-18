<?php

namespace Splicewire\Beam\Ux\Tests\JsonDoc;

use Splicewire\Beam\Ux\JsonDoc\JsonNode;
use Splicewire\Beam\Ux\Tests\TestCase;

class BindingDeclarationTest extends TestCase
{
    public function test_source_printer_emits_only_literal_field_helpers(): void
    {
        $body = $this->block();
        $body['repeat'] = ['collection' => 'articles'];
        $body['when'] = ['all' => [['field' => 'title', 'operator' => 'is set']]];
        $body['props'] = [['name' => 'title', 'kind' => 'field', 'value' => 'title']];
        $body['children'] = [['kind' => 'text', 'value' => 'Untitled', 'field' => 'title']];
        $printed = \Splicewire\Beam\Ux\Codec\JsonDocPrinter::print([$body]);
        $this->assertStringContainsString('title={bdField("title")}', $printed);
        $this->assertStringContainsString('{bdText("title", "Untitled")}', $printed);
        $this->assertStringContainsString('bdRepeat={', $printed);
        $this->assertStringContainsString('bdWhen={', $printed);
    }

    public function test_module_uses_the_request_payload_and_never_executes_source_helpers(): void
    {
        $body = $this->block();
        $body['id'] = 'repeat-one';
        $body['repeat'] = ['collection' => 'articles', 'empty' => [['kind' => 'text', 'value' => 'Empty']]];
        $body['children'] = [['kind' => 'text', 'value' => 'Untitled', 'field' => 'title']];
        $module = \Splicewire\Beam\Ux\Codec\JsonDocPrinter::printModule([$body]);
        $this->assertStringContainsString('bindingRuntime.repeat(bindings["repeat-one"]', $module);
        $this->assertStringContainsString('bindingRuntime.text(record, "title", "Untitled")', $module);
        $this->assertStringNotContainsString('bdText(', $module);
        $this->assertStringNotContainsString('bdRepeat=', $module);
        $this->assertStringContainsString('Empty', $module);
    }

    public function test_hydration_preserves_repeat_empty_state_conditions_and_field_bindings(): void
    {
        $body = $this->block();
        $body['repeat'] = ['collection' => 'articles', 'query' => ['limit' => 10], 'empty' => [
            ['kind' => 'text', 'value' => 'No articles'],
        ]];
        $body['when'] = ['all' => [['field' => 'title', 'operator' => 'is set']]];
        $body['props'] = [['name' => 'title', 'kind' => 'field', 'value' => 'title']];
        $body['children'] = [['kind' => 'text', 'value' => 'Untitled', 'field' => 'title']];
        $node = JsonNode::from($body)->toArray();
        $this->assertSame('articles', $node['repeat']['collection']);
        $this->assertSame(10, $node['repeat']['query']['limit']);
        $this->assertSame('No articles', $node['repeat']['empty'][0]['value']);
        $this->assertSame('is set', $node['when']['all'][0]['operator']);
        $this->assertSame('field', $node['props'][0]['kind']);
        $this->assertSame('title', $node['children'][0]['field']);
    }

    public function test_binding_paths_are_data_not_executable_expressions(): void
    {
        foreach (['x()', 'x[0]', '__proto__.x', 'x.constructor', '', 'x..y'] as $path) {
            try {
                JsonNode::from(['kind' => 'text', 'value' => '', 'field' => $path]);
                $this->fail('Admitted invalid binding path: '.$path);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('field path', $e->getMessage());
            }
        }
    }

    public function test_repeat_requires_exactly_one_source_and_conditions_use_the_adr_vocabulary(): void
    {
        foreach ([
            ['repeat' => ['collection' => 'articles', 'relation' => 'comments']],
            ['repeat' => ['query' => ['limit' => 10]]],
            ['repeat' => ['collection' => 'articles', 'query' => ['limit' => 0]]],
            ['when' => ['all' => [['field' => 'title', 'operator' => 'eq', 'value' => 'x']]]],
            ['when' => ['all' => [], 'any' => []]],
        ] as $declaration) {
            try {
                JsonNode::from([...$this->block(), ...$declaration]);
                $this->fail('Admitted malformed declaration: '.json_encode($declaration));
            } catch (\InvalidArgumentException|\ValueError|\Spatie\LaravelData\Exceptions\CannotCastEnum $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    private function block(): array
    {
        return ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []];
    }
}
