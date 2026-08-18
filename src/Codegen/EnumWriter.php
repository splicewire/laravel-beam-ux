<?php

namespace Splicewire\Beam\Ux\Codegen;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Emits a real PHP backed-string-enum's SOURCE TEXT from a list of case values — the `toEnum(...)`
 * primitive, hand-built directly (mirrors {@see \Splicewire\Beam\Ux\Codec\CssBodyCodec}'s own
 * hand-built CSS text: a format this class fully controls, so a plain string template is the RIGHT
 * amount of machinery, not a corner cut). No dependency on any codegen substrate — the whole point
 * `rushing/laravel-codegen`'s `CodegenModel`/`ModelSource`/`GeneratorRegistry` ceremony bought this
 * package nothing its actual need (one enum, with `implements`/`uses`, from a live list of strings)
 * couldn't get more simply and more completely (that package's `DataClassGenerator::buildEnum()` has
 * no `implements`/`uses` support at all today).
 *
 * `implements`/`uses` are the load-bearing part, not decoration: a class-string list of interfaces
 * the generated enum should `implements`, and traits it should `use`. This is what lets
 * `Splicewire\Beam\Ux\Codec\UxFormatCase` (or any interface a caller wants) actually reach the
 * generated class — see that interface's own docblock for why an enum implementing a shared,
 * `BackedEnum`-extending marker is what makes a registry genuinely open, not the code generation
 * itself.
 */
class EnumWriter
{
    /**
     * @param  array<int, string>  $cases  raw case VALUES (case NAMES are derived via `Str::studly()`,
     *                                     matching how a hand-written enum in this package is named)
     * @param  list<class-string>  $implements
     * @param  list<class-string>  $uses
     */
    public static function write(
        string $namespace,
        string $name,
        array $cases,
        array $implements = [],
        array $uses = [],
        ?string $doc = null,
    ): string {
        if ($cases === []) {
            throw new InvalidArgumentException("Refusing to write enum [{$name}] with zero cases.");
        }

        $implementsClause = $implements === [] ? '' : ' implements '.implode(', ', array_map(
            fn (string $fqcn) => '\\'.ltrim($fqcn, '\\'),
            $implements,
        ));

        $useLines = implode('', array_map(
            fn (string $fqcn) => '    use \\'.ltrim($fqcn, '\\').";\n",
            $uses,
        ));

        $caseLines = implode('', array_map(
            fn (string $value) => '    case '.self::caseName($value).' = '.var_export($value, true).";\n",
            $cases,
        ));

        $docBlock = $doc === null ? '' : "/**\n * ".str_replace("\n", "\n * ", $doc)."\n */\n";

        return <<<PHP
            <?php

            namespace {$namespace};

            {$docBlock}enum {$name}: string{$implementsClause}
            {
            {$useLines}{$caseLines}}

            PHP;
    }

    /** `'tsx'` → `'Tsx'`; `'app:operator'` → `'AppOperator'` — the same derivation a hand-written enum
     * in this package already follows by convention (`UxFormat::Tsx = 'tsx'`), so a generated case
     * name is never a surprise next to a hand-written sibling. */
    private static function caseName(string $value): string
    {
        return Str::studly(str_replace([':', '.'], '-', $value));
    }
}
