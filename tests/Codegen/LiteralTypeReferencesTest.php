<?php

namespace Splicewire\Beam\Ux\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\LaravelData\Data;

/** Literals cannot carry class references: a host's declaration walk cannot discover those types. */
class LiteralTypeReferencesTest extends TestCase
{
    public function test_no_typescript_literal_hides_a_data_or_enum_reference(): void
    {
        $sources = [];
        $classNames = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $sources[$file->getPathname()] = $source;
            preg_match_all('/\b(enum)\s+(\w+)|\bclass\s+(\w+Data)\b/', $source, $declarations, PREG_SET_ORDER);
            foreach ($declarations as $declaration) {
                $classNames[$declaration[2] ?: $declaration[3]] = true;
            }
        }

        $violations = [];
        foreach ($sources as $path => $source) {
            $names = $classNames;
            // Imported dependency enums/Data classes, including an explicitly aliased import.
            preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $imports, PREG_SET_ORDER);
            foreach ($imports as $import) {
                $class = $import[1];
                if (enum_exists($class) || is_subclass_of($class, Data::class)) {
                    $parts = explode('\\', $class);
                    $names[$import[2] ?? end($parts)] = true;
                }
            }
            $tokens = token_get_all($source);
            $attributeDepth = 0;
            foreach ($tokens as $offset => $token) {
                if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                    $attributeDepth++;
                } elseif ($attributeDepth > 0 && $token === '[') {
                    $attributeDepth++;
                } elseif ($attributeDepth > 0 && $token === ']') {
                    $attributeDepth--;
                }
                if ($attributeDepth === 0 || ! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    || ! str_ends_with($token[1], 'LiteralTypeScriptType')) {
                    continue;
                }
                for ($next = $offset + 1; $next < count($tokens); $next++) {
                    $argument = $tokens[$next];
                    if ($argument === ')') {
                        break;
                    }
                    if (! is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) {
                        continue;
                    }
                    foreach (preg_split('/[^A-Za-z0-9_]+/', $argument[1]) as $name) {
                        if (isset($names[$name]) || preg_match('/^[A-Z]\w*Data$/', $name)) {
                            $violations[] = basename($path).':'.$token[2].' hides '.$name;
                        }
                    }
                    break;
                }
            }
        }

        $this->assertSame([], $violations, 'Use TypeScriptType for class references; literals are only for self-contained TypeScript.');
    }
}
