<?php

namespace Splicewire\Beam\Ux\Tests\Codegen;

use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\Actions\TransformTypesAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Visitor\Visitor;
use Splicewire\Beam\Ux\Data\ComponentArtifactData;
use Splicewire\Beam\Ux\Data\ComponentCanvasData;
use Splicewire\Beam\Ux\Data\RepeatRecordsData;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * A host emits a Data type only when its generator REACHES it: laravel-beam's DeclaredParticleTransformedProvider walks
 * out from the declared DTOs along their typed class references. A component's `bindings` named RepeatRecordsData in a
 * raw TypeScript literal, which references nothing, so every fresh starter's generated Ux/Data/index.ts used the name
 * without defining it and the asset build failed (TS2304; the EP-18 receipt, 2026-10-25). This transforms both classes
 * the way the starter's generator does (its laravel-data extension and enum transformer) and asserts the walk reaches it.
 */
class ComponentBindingsTypeTest extends TestCase
{
    public static function components(): array
    {
        return ['an artifact' => [ComponentArtifactData::class], 'a canvas' => [ComponentCanvasData::class]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('components')]
    public function test_a_components_bindings_reference_the_repeat_records_type_the_generator_follows(string $class): void
    {
        // Nothing is scanned: the classes are handed to the transformers directly, as the provider hands them.
        $empty = sys_get_temp_dir().'/beam-ux-ts-'.bin2hex(random_bytes(4));
        mkdir($empty);
        $config = TypeScriptTransformerConfigFactory::create()
            ->transformDirectories($empty)
            ->outputDirectory($empty)
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(EnumTransformer::class)
            ->get();

        $transformed = (new TransformTypesAction)->transformClassNode($config->transformers, PhpClassNode::fromClassString($class));
        $this->assertNotNull($transformed);
        $referenced = [];
        Visitor::create()->before(function (TypeScriptReference $reference) use (&$referenced): void {
            if ($reference->reference instanceof ClassStringReference) {
                $referenced[] = $reference->reference->classString;
            }
        }, [TypeScriptReference::class])->execute($transformed->getNode());

        rmdir($empty);
        $this->assertContains(RepeatRecordsData::class, $referenced);
    }
}
