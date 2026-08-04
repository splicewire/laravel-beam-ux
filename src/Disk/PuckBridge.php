<?php

namespace Splicewire\Beam\Ux\Disk;

use RuntimeException;
use Splicewire\Beam\Ux\Codegen\PuckPageCodegen;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * The PHP side of the **PuckData ⟷ BlockDoc bridge** (beam Model-B ticket 08) — the seam that lets the
 * reverse file→DB sync leg parse a composed page `.tsx` back into a Puck `Data` body.
 *
 * Lossless JSX↔PuckData parsing needs the JS toolchain (recast + babel), so it lives in the Node
 * `@splicewire/beam-ux` package, NOT here (the same "codegen lives with the JS toolchain" split
 * `PuckPageCodegen` is the outbound half of). This class shells to that package's `beam-ux-puck-bridge`
 * CLI (via Symfony Process) with a page's source on stdin and reads the Puck Data JSON back.
 *
 * **Degrade-not-fabricate:** {@see available()} reports whether the Node interpreter + the bridge script
 * are both present. When they are not, the reverse page-sync is UNAVAILABLE — the caller must fall back
 * to the raw-source path rather than fabricate an empty page. The bridge is never a mandatory failure.
 *
 * **Config (`beam.ux.puck.bridge`):**
 *  - `node`   — the node interpreter (default `node`, resolved on PATH).
 *  - `script` — absolute path to `bin/puck-bridge.mjs` in the resolved `@splicewire/beam-ux` package.
 *               Null/unset ⇒ the bridge is unavailable (degrade).
 */
class PuckBridge
{
    public function __construct(
        protected ?string $script = null,
        protected string $node = 'node',
        protected int $timeout = 30,
    ) {}

    /** Is the Node bridge usable — the script path configured AND the file present on disk? */
    public function available(): bool
    {
        return $this->script !== null && $this->script !== '' && is_file($this->script);
    }

    /**
     * Parse a composed page `.tsx` back into a Puck `Data` document ({root, content, zones}).
     *
     * Returns null when the file has no page-shaped root (the CLI's exit-2 signal) — a caller falls
     * back to raw-source handling. Throws when the bridge is unavailable or the CLI errors.
     *
     * @return array<string, mixed>|null
     */
    public function toPuck(string $tsx): ?array
    {
        if (! $this->available()) {
            throw new RuntimeException('The beam-ux puck bridge is unavailable (config beam.ux.puck.bridge.script).');
        }

        $process = new Process([$this->node, $this->script, 'to-puck'], null, null, $tsx, $this->timeout);
        $process->run();

        // Exit 2 = "no page-shaped root": not an error, a degrade signal (caller keeps raw source).
        if ($process->getExitCode() === 2) {
            return null;
        }

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $decoded = json_decode(trim($process->getOutput()), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Compile a Puck `Data` body back to a composed-JSX `.tsx` (the outbound direction, via the same
     * CLI). PHP's {@see PuckPageCodegen} is the primary outbound codegen;
     * this exists so a host can prove the JS + PHP codegens agree byte-for-byte.
     *
     * @param  array<string, mixed>  $data
     */
    public function toTsx(array $data, string $slug, ?string $blocksModule = null): string
    {
        if (! $this->available()) {
            throw new RuntimeException('The beam-ux puck bridge is unavailable (config beam.ux.puck.bridge.script).');
        }

        $args = [$this->node, $this->script, 'to-tsx', $slug];
        if ($blocksModule !== null) {
            $args[] = $blocksModule;
        }

        $process = new Process($args, null, null, json_encode($data), $this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
