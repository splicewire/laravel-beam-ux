<?php

namespace Splicewire\Beam\Ux\Tests\JsonDoc;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Bindings\BindingFieldValidator;
use Splicewire\Beam\Ux\Tests\TestCase;

class BindingFieldValidationTest extends TestCase
{
    public function test_rich_fields_require_the_matching_safe_component_and_never_admit_mdx_or_html_sinks(): void
    {
        $profile = $this->profile();
        $profile['x-collections'][0]['schema']['properties']['code'] = ['type' => 'string', 'format' => 'mdx'];
        foreach ([['div', 'content', 'body'], ['RichBlockdoc', 'content', 'body'],
            ['RichMarkdown', 'content', 'code'], ['div', 'dangerouslySetInnerHTML', 'cover'], ['div', 'srcDoc', 'title']] as [$node, $prop, $field]) {
            try {
                (new BindingFieldValidator)->assert([['kind' => 'block', 'name' => $node, 'repeat' => ['collection' => 'articles'],
                    'props' => [['name' => $prop, 'kind' => 'field', 'value' => $field]]]], $profile);
                $this->fail('Accepted an unsafe rich field sink '.$node.'.'.$prop);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
            }
        }
        (new BindingFieldValidator)->assert([['kind' => 'block', 'name' => 'RichMarkdown', 'repeat' => ['collection' => 'articles'],
            'props' => [['name' => 'content', 'kind' => 'field', 'value' => 'body']]]], $profile);
        $this->addToAssertionCount(1);
    }

    public function test_prop_bindings_must_fit_the_declared_attribute_type_and_enum(): void
    {
        $profile = $this->profile();
        $profile['nodes'] = [['name' => 'Card', 'attrsSchema' => ['type' => 'object', 'properties' => [
            'count' => ['type' => 'number'],
            'tone' => ['type' => 'string', 'enum' => ['quiet', 'loud']],
        ], 'additionalProperties' => false]]];
        $profile['x-collections'][0]['schema']['properties'] += [
            'count' => ['type' => 'integer'],
            'tone' => ['type' => 'string', 'enum' => ['quiet']],
        ];
        foreach ([['count', 'title'], ['tone', 'title'], ['unknown', 'title']] as [$name, $field]) {
            $body = [['kind' => 'block', 'name' => 'Card', 'repeat' => ['collection' => 'articles'],
                'props' => [['kind' => 'field', 'name' => $name, 'value' => $field]]]];
            try {
                (new BindingFieldValidator)->assert($body, $profile);
                $this->fail('Accepted incompatible binding '.$name.' <- '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
            }
        }
        (new BindingFieldValidator)->assert([['kind' => 'block', 'name' => 'Card', 'repeat' => ['collection' => 'articles'],
            'props' => [['kind' => 'field', 'name' => 'count', 'value' => 'count'], ['kind' => 'field', 'name' => 'tone', 'value' => 'tone']]]], $profile);
        $this->addToAssertionCount(1);
    }

    public function test_paths_use_the_offered_schema_and_plain_text_refuses_rich_fields(): void
    {
        foreach (['missing', 'url', 'body', 'cover.missing'] as $field) {
            try {
                (new BindingFieldValidator)->assert($this->body($field), $this->profile());
                $this->fail('Accepted '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
            }
        }
        (new BindingFieldValidator)->assert($this->body('cover.title'), $this->profile());
        $this->addToAssertionCount(1);
    }

    public function test_a_field_requires_a_record_scope_and_an_empty_state_has_no_repeated_record(): void
    {
        foreach ([
            [['kind' => 'text', 'field' => 'title', 'value' => '']],
            [['kind' => 'block', 'repeat' => ['collection' => 'articles', 'empty' => [['kind' => 'text', 'field' => 'title', 'value' => '']]], 'children' => []]],
            [['kind' => 'block', 'repeat' => ['collection' => 'not-offered'], 'children' => []]],
        ] as $body) {
            try {
                (new BindingFieldValidator)->assert($body, $this->profile());
                $this->fail('Accepted a field without its declared scope');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
            }
        }
    }

    public function test_conditions_and_props_use_the_same_paths_and_child_pages_can_bind_url(): void
    {
        $body = $this->body('title');
        $body[0]['when'] = ['all' => [['field' => 'secret', 'operator' => 'is set']]];
        try {
            (new BindingFieldValidator)->assert($body, $this->profile());
            $this->fail('Accepted an undeclared condition field');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
        unset($body[0]['when']);
        $body[0]['props'] = [['kind' => 'field', 'name' => 'href', 'value' => 'url']];
        $profile = $this->profile();
        $profile['x-collections'][0]['hasRecordPage'] = true;
        (new BindingFieldValidator)->assert($body, $profile);
        $this->addToAssertionCount(1);
    }

    private function body(string $field): array
    {
        return [['kind' => 'block', 'repeat' => ['collection' => 'articles'], 'children' => [['kind' => 'text', 'value' => '', 'field' => $field]]]];
    }

    private function profile(): array
    {
        return ['x-collections' => [['name' => 'articles', 'hasRecordPage' => false, 'schema' => [
            'type' => 'object', 'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string', 'format' => 'markdown'],
                'cover' => ['$ref' => '#/$defs/cover'],
            ], '$defs' => ['cover' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]],
        ]]]];
    }
}
