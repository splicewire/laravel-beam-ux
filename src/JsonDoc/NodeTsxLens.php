<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * ADR-0228 d9 — the default {@see TsxLens}: shells out to `resources/lens/tsx-to-blocks.mjs`, mirroring
 * {@see \Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler}. Source goes in as JSON on stdin with the host
 * `root` (so the script resolves `@splicewire/beam-ux/blockdoc` from the HOST's node_modules, closing the
 * Composer path-repo resolution trap the compile worker documents); the id-less JsonDoc comes back as
 * JSON on stdout. A lens failure is the author's source, so it surfaces as a 422 on `source`, never a 500.
 */
final class NodeTsxLens implements ProfiledTsxLens
{
    public function __construct(
        private string $binary = 'node',
        private ?string $script = null,
        private ?string $workingDirectory = null,
        private float $timeout = 60.0,
    ) {}

    public function lower(string $source): array
    {
        return $this->run($source, []);
    }

    /** Explicit enclosing manifest entry; never infer a component interface from source identifiers. */
    public function lowerInComponent(string $source, array $profile, string $component): array
    {
        return $this->run($source, ['profile' => $profile, 'component' => $component]);
    }

    /** Lower an instance's JSX-valued slots against the same resolved manifest as save. */
    public function lowerWithProfile(string $source, array $profile): array
    {
        return $this->run($source, ['profile' => $profile]);
    }

    private function run(string $source, array $context): array
    {
        $script = $this->script ?? __DIR__.'/../../resources/lens/tsx-to-blocks.mjs';

        if (! is_file($script)) {
            throw ValidationException::withMessages(['source' => "the TSX lens script is missing at [{$script}]."]);
        }

        $root = $this->workingDirectory ?? base_path();

        $process = new Process(
            [$this->binary, $script],
            $this->workingDirectory,
            null,
            json_encode(['source' => $source, 'root' => $root, 'context' => $context], JSON_THROW_ON_ERROR),
            $this->timeout,
        );

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            // stderr carries the lens's own diagnostic (a TSX syntax error, a missing host dependency).
            throw ValidationException::withMessages(['source' => trim($process->getErrorOutput()) ?: $e->getMessage()]);
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded) || ! is_array($decoded['doc'] ?? null)) {
            throw ValidationException::withMessages(['source' => 'the TSX lens returned no doc.']);
        }

        /** @var array<int, array<string, mixed>> */
        return $decoded['doc'];
    }
}
