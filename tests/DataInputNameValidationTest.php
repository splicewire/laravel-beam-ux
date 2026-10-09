<?php

namespace Splicewire\Beam\Ux\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;

class DataInputNameValidationTest extends TestCase
{
    #[Test]
    #[DataProvider('inputMapperConfigurations')]
    public function every_package_validation_key_matches_its_resolved_input_name(?string $inputMapper): void
    {
        config()->set('data.name_mapping_strategy.input', $inputMapper);
        app(DataConfig::class)->reset();
        $classes = $this->dataClasses();

        $this->assertSame($inputMapper, config('data.name_mapping_strategy.input'));
        $this->assertNotEmpty($classes);
        $this->assertSame([], $this->inputRuleNameMismatches($classes));
    }

    /** @return array<string, array{class-string|null}> */
    public static function inputMapperConfigurations(): array
    {
        return [
            'no global input mapper' => [null],
            'global camel-case input mapper' => [CamelCaseMapper::class],
        ];
    }

    /** @return list<class-string<Data>> */
    private function dataClasses(): array
    {
        $sourceRoot = dirname(__DIR__).'/src/';
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($sourceRoot), -4);
            $class = 'Splicewire\\Beam\\Ux\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class) || ! is_subclass_of($class, Data::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $reflection->isAbstract()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param  list<class-string<Data>>  $classes
     * @return list<string>
     */
    private function inputRuleNameMismatches(array $classes): array
    {
        $mismatches = [];
        $dataConfig = app(DataConfig::class);

        foreach ($classes as $class) {
            $inputNames = $dataConfig->getDataClass($class)->properties
                ->filter(fn ($property) => $property->validate)
                ->map(fn ($property) => $property->inputMappedName ?? $property->name)
                ->values()
                ->all();

            foreach (array_keys($class::getValidationRules([])) as $ruleKey) {
                $matchesProperty = collect($inputNames)->contains(
                    fn (string $inputName) => $ruleKey === $inputName || str_starts_with($ruleKey, $inputName.'.')
                );

                if (! $matchesProperty) {
                    $mismatches[] = "{$class}: {$ruleKey}";
                }
            }
        }

        return $mismatches;
    }
}
