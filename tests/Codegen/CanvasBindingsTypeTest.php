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
use Splicewire\Beam\Ux\Data\CanvasRecordChoiceData;
use Splicewire\Beam\Ux\Data\EntryCanvasBindingsOutputData;
use Splicewire\Beam\Ux\Data\RepeatRecordsData;
use Splicewire\Beam\Ux\Tests\TestCase;

/** A starter follows typed references from declared operation output; raw TypeScript literals hide them. */
class CanvasBindingsTypeTest extends TestCase
{
    public static function references(): array
    {
        return ['bindings' => [RepeatRecordsData::class], 'record choices' => [CanvasRecordChoiceData::class]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('references')]
    public function test_canvas_output_references_are_followed_by_the_starter_generator(string $expected): void
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

        $transformed = (new TransformTypesAction)->transformClassNode($config->transformers, PhpClassNode::fromClassString(EntryCanvasBindingsOutputData::class));
        $this->assertNotNull($transformed);
        $referenced = [];
        Visitor::create()->before(function (TypeScriptReference $reference) use (&$referenced): void {
            if ($reference->reference instanceof ClassStringReference) {
                $referenced[] = $reference->reference->classString;
            }
        }, [TypeScriptReference::class])->execute($transformed->getNode());

        rmdir($empty);
        $this->assertContains($expected, $referenced);
    }
}
