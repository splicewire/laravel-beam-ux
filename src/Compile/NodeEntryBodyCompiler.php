<?php

namespace Splicewire\Beam\Ux\Compile;

use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * The default {@see EntryBodyCompiler} — a Node process over the package's own `compile.mjs`, resolving
 * `@mdx-js/mdx` and `esbuild` **from the host's `node_modules`**, not from anything beam-ux vendors.
 *
 * **Node at save time is the deliberate half of ADR-0209 §7's trade.** Every beam-ux host already needs
 * Node to build its assets, and compiling where content CHANGES (rare) rather than where content is READ
 * (constant) is the cheaper side — the alternative pays an MDX compiler's download on every page view,
 * forever, for a cost that is invisible until an audit finds it.
 *
 * The host owns the toolchain, so the host owns the failure: a missing `@mdx-js/mdx` is a
 * {@see CompilationFailed} naming the package to install, never a fallback to some lesser output.
 * `beam.ux.compile.binary` / `.script` / `.timeout` exist so a host with a pinned Node, a wrapper, or a
 * warm compile service points at it without re-implementing this class.
 */
class NodeEntryBodyCompiler implements EntryBodyCompiler
{
    /** The formats `compile.mjs` knows how to turn into an ES module. `css` is a theme body, not a page. */
    public const FORMATS = [UxFormat::Mdx->value, UxFormat::Tsx->value];

    public function __construct(
        private string $binary = 'node',
        private ?string $script = null,
        private ?string $workingDirectory = null,
        private float $timeout = 60.0,
    ) {}

    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, self::FORMATS, true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        if (! $this->handles($entry)) {
            throw CompilationFailed::unsupported($entry);
        }

        $script = $this->script ?? __DIR__.'/../../resources/compile/compile.mjs';

        if (! is_file($script)) {
            throw CompilationFailed::for($entry, "the compile script is missing at [{$script}].");
        }

        $process = new Process(
            [$this->binary, $script],
            $this->workingDirectory,
            null,
            json_encode([
                'format' => $entry->format?->value,
                'slug' => (string) $entry->slug,
                'source' => $source,
            ], JSON_THROW_ON_ERROR),
            $this->timeout,
        );

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            // stderr carries the compiler's own diagnostic (an MDX syntax error, a missing dependency).
            // Surfacing it verbatim is the point: "compilation failed" with no reason is what sends
            // someone to the browser-fallback that §7 forbids.
            throw CompilationFailed::for($entry, trim($process->getErrorOutput()) ?: $e->getMessage());
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded) || ! is_string($decoded['code'] ?? null)) {
            throw CompilationFailed::for($entry, 'the compile script returned no `code`.');
        }

        return $decoded['code'];
    }
}
