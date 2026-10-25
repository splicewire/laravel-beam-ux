<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class AuthoredComponentsTest extends TestCase
{
    public function test_authored_body_kind_is_explicit_and_legacy_components_remain_source(): void
    {
        $legacy = new BeamUxEntry(['type' => 'component', 'format' => 'tsx']);
        $this->assertSame('source', $legacy->bodyKind()->value);
        $authored = new BeamUxEntry(['type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored']);
        $this->assertSame('blocks', $authored->bodyKind()->value);
    }

    public function test_image_prop_schema_retains_the_explicit_picker_type_and_string_value(): void
    {
        $prop = new \Splicewire\Beam\Ux\Components\ComponentPropData(
            name: 'cover',
            type: \Splicewire\Beam\Ux\Components\ComponentPropType::Image,
            default: '/images/cover.png',
        );
        $this->assertSame('image', $prop->schema()['x-component-prop-type']);
        $this->assertSame('string', $prop->schema()['type']);
        $this->assertTrue($prop->accepts('/images/next.png'));
        $this->assertFalse($prop->accepts(['url' => '/images/next.png']));
    }

    public function test_empty_component_interface_still_emits_a_valid_closed_object_schema(): void
    {
        $definition = new \Splicewire\Beam\Ux\Components\ComponentDefinitionData('EmptyCard');
        $schema = json_decode(json_encode($definition->schema(), JSON_THROW_ON_ERROR));
        $validator = new \Opis\JsonSchema\Validator;
        $this->assertTrue($validator->validate(new \stdClass, $schema)->isValid());
        $this->assertFalse($validator->validate((object) ['unknown' => 'refused'], $schema)->isValid());
    }

    public function test_components_layouts_and_templates_are_independently_compilable(): void
    {
        foreach (['component', 'layout', 'template', 'page'] as $type) {
            $entry = new BeamUxEntry(['type' => $type, 'format' => 'tsx']);
            $this->assertTrue(app(CompileEntryBody::class)->compilable($entry), $type.' must have its own artifact');
        }
        $this->assertFalse(app(CompileEntryBody::class)->compilable(new BeamUxEntry(['type' => 'theme', 'format' => 'css'])));
    }

    public function test_uninitialized_tenant_catalog_never_queries_a_central_fallback_or_initializes_a_connection(): void
    {
        $entry = new BeamUxEntry(['type' => 'page', 'realm' => 'site']);
        $entry->setConnection('ep20_uninitialized_tenant');
        $before = config('database.connections');
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->assertSame([], app(\Splicewire\Beam\Ux\Components\AuthoredComponents::class)->entries($entry));
        $this->assertSame([], $queries);
        $this->assertSame($before, config('database.connections'));
    }
}
