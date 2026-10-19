<?php

namespace Splicewire\Beam\Ux\Compile;

use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * A compiler that can check its host toolchain **without compiling anything** (SS-02).
 *
 * A separate, optional port beside {@see EntryBodyCompiler} rather than a method on it, so a host that
 * binds its own compiler keeps compiling and simply has no preflight ({@see CompileEntryBody::preflight()}
 * then trusts the compile itself to fail loudly, as it always has).
 *
 * The check exists for callers that write several things before their first compile — a host data
 * migration that copies pages and then publishes them, a batch import — and would otherwise stop with
 * part of their work written. Measured on schemastud 2026-10-19: `migrate` ran 5 of 15 migrations, then a
 * page-copy migration died on its first publish because `esbuild` was declared in package.json but never
 * installed.
 */
interface PreflightsToolchain
{
    /**
     * Resolve what `$format` compiles with on this host, the same way a compile would, and compile nothing.
     * A format this compiler does not handle has no toolchain to check and passes.
     *
     * @throws CompilationFailed naming the missing piece and the command that installs it
     */
    public function preflight(UxFormat $format): void;
}
