<?php

namespace Splicewire\Beam\Ux\Shell;

/**
 * Where a host's front-end lives, for the shell stamp. A host's generated SDK and the shell's own files share one
 * root: the satellite default is `resources/js`, and the platform (an npm-workspace app) overrides it to `ui/src`.
 *
 * `splicewire:beam:ux:stamp-shell` writes two files whose location MUST track that root, or the stamped
 * `frame-resource-map.ts` imports a `@/generated/frame-resources` module that is not there:
 *   - the resource map    → `<root>/frame-resource-map.ts`
 *   - the generated module → `<root>/generated/frame-resources.ts`  (what `splicewire:beam:generate:client` writes)
 *
 * The one knob is `beam.client.out_dir` — already the single source of truth for where the generator writes, so the
 * stamp can never disagree with it. {@see \Splicewire\Beam\Console\GenerateClientSdkCommand} resolves it the same way
 * (a non-empty string wins, else `base_path('resources/js/generated')`); a parity test compares against that method.
 *
 * ## Resolving `beam.client.out_dir` to a host-relative root (the rule)
 * The stamp writes under the host's base path, so these targets are host-RELATIVE. `beam.client.out_dir`:
 *   1. unset / empty                      → the satellite default `resources/js/generated`, against the current base.
 *   2. relative, or absolute and under the base path (after normalising `.`/`..`) → used as-is. The real host case:
 *      a satellite's default, or the platform's `base_path('ui/src/generated')` override.
 *   3. absolute outside the base path, or a `..` that escapes it (either `/base/../web/...` or `../web/...`)
 *      → FAILS CLOSED ({@see self::configError()}). The stamp never writes outside the app; silently re-rooting a
 *        misconfigured out_dir (a monorepo sibling, a `config:cache` built at another path) is the stamp/generator
 *        disagreement this lift removes. A test harness must set `beam.client.out_dir` to null AFTER relocating the
 *        base path, so the package default applies against the current base, as a real host has it.
 * `basename` other than `generated` also fails closed (the stub imports `@/generated/frame-resources`).
 */
final class FrontEndRoot
{
    /** The satellite default generated-SDK dir, host-relative. */
    public const DEFAULT_GENERATED = 'resources/js/generated';

    /**
     * The generated-SDK dir host-relative to the current base path, or null when `beam.client.out_dir` resolves
     * outside the app (the fail-closed case). See the class docblock for the rule.
     */
    private static function resolved(): ?string
    {
        $configured = config('beam.client.out_dir');

        if (! is_string($configured) || $configured === '') {
            return self::DEFAULT_GENERATED;
        }

        $base = self::normalise(base_path());
        $absolute = str_starts_with($configured, DIRECTORY_SEPARATOR)
            ? $configured
            : $base.DIRECTORY_SEPARATOR.$configured;
        $normalised = self::normalise($absolute);

        if ($normalised === $base) {
            return '';                                          // out_dir == base (basename check then fails it)
        }
        if (str_starts_with($normalised, $base.DIRECTORY_SEPARATOR)) {
            return substr($normalised, strlen($base) + 1);
        }

        return null;                                            // outside the base path → fail closed
    }

    /** The generated-SDK dir host-relative to the current base path (fails over for path use; configError refuses it). */
    public static function generatedRelative(): string
    {
        return self::resolved() ?? self::DEFAULT_GENERATED;
    }

    /** The absolute generated-SDK dir under the current base path. */
    public static function generatedDir(): string
    {
        return base_path(self::generatedRelative());
    }

    /** Host-relative target of the generated module the map imports: `<generated>/frame-resources.ts`. */
    public static function frameResourcesTarget(): string
    {
        return self::generatedRelative().'/frame-resources.ts';
    }

    /** Host-relative target of the stamped resource map, beside the generated dir: `<root>/frame-resource-map.ts`. */
    public static function frameResourceMapTarget(): string
    {
        $root = dirname(self::generatedRelative());

        return ($root === '.' || $root === '' ? '' : $root.'/').'frame-resource-map.ts';
    }

    /**
     * Fail closed: the stamp must not write a map that imports a module that cannot resolve, nor write outside the app.
     * An out_dir that resolves outside the base path, or a generated dir not named `generated`, is refused with a clear
     * message. Null when the root is usable.
     */
    public static function configError(): ?string
    {
        $relative = self::resolved();

        if ($relative === null) {
            return 'beam.client.out_dir resolves outside the application (base_path) — the shell stamp writes the '
                .'resource map beside it; got ['.config('beam.client.out_dir').'].';
        }

        if (basename($relative) !== 'generated') {
            return "beam.client.out_dir must end in a 'generated' directory — the stamped resource map imports "
                ."'@/generated/frame-resources'; got [{$relative}].";
        }

        return null;
    }

    /** Collapse `.` and `..` segments without touching the filesystem (the dirs may not exist yet). */
    private static function normalise(string $path): string
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $out = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (! $isAbsolute) {
                    $out[] = '..';
                }

                continue;
            }
            $out[] = $segment;
        }

        return ($isAbsolute ? DIRECTORY_SEPARATOR : '').implode(DIRECTORY_SEPARATOR, $out);
    }
}
