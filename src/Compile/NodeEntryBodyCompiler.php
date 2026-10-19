<?php

namespace Splicewire\Beam\Ux\Compile;

use Illuminate\Support\Facades\Log;
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
class NodeEntryBodyCompiler implements EntryBodyCompiler, PreflightsToolchain
{
    /** The formats `compile.mjs` knows how to turn into an ES module. `css` is a theme body, not a page. */
    public const FORMATS = [UxFormat::Mdx->value, UxFormat::Tsx->value];

    /** @var list<array{rule: string, line: int, message: string}> */
    private array $lastWarnings = [];

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

        $script = $this->script();

        if (! is_file($script)) {
            throw CompilationFailed::for($entry, "the compile script is missing at [{$script}].");
        }

        $process = $this->process($script, [
            'format' => $entry->format?->value,
            'slug' => (string) $entry->slug,
            'source' => $source,
        ]);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            // stderr carries the compiler's own diagnostic (an MDX syntax error, a missing dependency).
            // Surfacing it verbatim is the point: "compilation failed" with no reason is what sends
            // someone to the browser-fallback that §7 forbids.
            throw CompilationFailed::for($entry, $this->reason($process, $e));
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded) || ! is_string($decoded['code'] ?? null)) {
            throw CompilationFailed::for($entry, 'the compile script returned no `code`.');
        }

        // DOC-15 warnings (a hard-wrapped list marker): reported, never a refusal.
        $this->lastWarnings = array_values(array_filter((array) ($decoded['warnings'] ?? []), 'is_array'));
        foreach ($this->lastWarnings as $warning) {
            Log::warning('beam-ux compile: '.$entry->slug.' '.($warning['message'] ?? ($warning['rule'] ?? 'warning')));
        }

        return $decoded['code'];
    }

    /**
     * Resolve `$format`'s dependencies from the host through `compile.mjs`'s own `load()`, compiling
     * nothing (SS-02). One resolver for the probe and the compile, so a passing preflight means the
     * compile's resolution passes too.
     */
    public function preflight(UxFormat $format): void
    {
        if (! in_array($format->value, self::FORMATS, true)) {
            return;
        }

        $script = $this->script();

        if (! is_file($script)) {
            throw CompilationFailed::toolchain($format->value, "the compile script is missing at [{$script}].");
        }

        $process = $this->process($script, ['format' => $format->value, 'probe' => true]);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw CompilationFailed::toolchain($format->value, $this->reason($process, $e));
        }
    }

    /**
     * The warnings the last compile reported (DOC-15), each `{rule, line, message}`.
     *
     * @return list<array{rule: string, line: int, message: string}>
     */
    public function lastWarnings(): array
    {
        return $this->lastWarnings;
    }

    private function script(): string
    {
        return $this->script ?? __DIR__.'/../../resources/compile/compile.mjs';
    }

    /** @param  array<string, mixed>  $input */
    private function process(string $script, array $input): Process
    {
        return new Process(
            [$this->binary, $script],
            $this->workingDirectory,
            null,
            json_encode($input + [
                // The host root, passed explicitly because PHP is the only side that knows it. Node
                // resolves bare specifiers from the importing module's REAL path, and `compile.mjs`
                // lives in this package — which reaches the host's node_modules only when the package
                // is physically nested under it. A Composer path repository symlinks it out of the
                // tree entirely, so the script would look for `@mdx-js/mdx` beside the package source
                // and report it missing while it sat installed in the host. See `load()` in the script.
                'root' => $this->workingDirectory ?? base_path(),
            ], JSON_THROW_ON_ERROR),
            $this->timeout,
        );
    }

    /**
     * Why the process failed, in words that name the fix. Exit 126/127 is the shell reporting that the
     * binary itself could not run (no Node on the PATH, or a wrong `beam.ux.compile.binary`), which the
     * script's own diagnostics never cover because the script never started.
     */
    private function reason(Process $process, ProcessFailedException $e): string
    {
        $stderr = trim($process->getErrorOutput());

        if (in_array($process->getExitCode(), [126, 127], true)) {
            return "Node could not run as [{$this->binary}] ({$stderr}). beam-ux compiles with the host's Node: "
                .'install it, or point `beam.ux.compile.binary` (BEAM_UX_NODE_BINARY) at it.';
        }

        return $stderr ?: $e->getMessage();
    }
}
