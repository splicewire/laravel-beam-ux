<?php

namespace Splicewire\Beam\Ux\Tests\Codegen;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\LaravelData\Data;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\Actions\TransformTypesAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Visitor\Visitor;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * A host's `typescript:transform` can replace a class reference only with a type it transformed: a Data class or an
 * enum. A property typed as anything else (EntryImageUploadData's `UploadedFile`) leaves the host's generator printing
 * "not found in the transformed types", which laravel-tower-starter's TypeScriptGenerationTest refuses (launch row
 * qitem-20261029210327-185c04be). This transforms every package Data class the way the starter's generator does and
 * asserts each class reference it emits is one the host can resolve; a non-Data type declares its TypeScript shape.
 */
class ResolvableTypeReferencesTest extends TestCase
{
    public function test_every_data_class_references_only_types_a_host_generator_transforms(): void
    {
        $empty = sys_get_temp_dir().'/beam-ux-ts-'.bin2hex(random_bytes(4));
        mkdir($empty);
        $config = TypeScriptTransformerConfigFactory::create()
            ->transformDirectories($empty)
            ->outputDirectory($empty)
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(EnumTransformer::class)
            ->get();

        $unresolvable = [];
        foreach ($this->dataClasses() as $class) {
            $transformed = (new TransformTypesAction)->transformClassNode($config->transformers, PhpClassNode::fromClassString($class));
            if ($transformed === null) {
                continue;
            }
            Visitor::create()->before(function (TypeScriptReference $reference) use (&$unresolvable, $class): void {
                $target = $reference->reference instanceof ClassStringReference ? $reference->reference->classString : null;
                if ($target !== null && ! enum_exists($target) && ! is_subclass_of($target, Data::class)) {
                    $unresolvable[] = "{$class} references {$target}";
                }
            }, [TypeScriptReference::class])->execute($transformed->getNode());
        }
        rmdir($empty);

        $this->assertSame([], $unresolvable);
    }

    /** @return list<class-string<Data>> */
    private function dataClasses(): array
    {
        $src = realpath(__DIR__.'/../../src');
        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'Splicewire\\Beam\\Ux\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($src) + 1));
            try {
                $loads = class_exists($class);
            } catch (\Throwable) {
                continue; // an optional integration's class (laravel-data-schema-org) cannot load where it is absent
            }
            if ($loads && is_subclass_of($class, Data::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
