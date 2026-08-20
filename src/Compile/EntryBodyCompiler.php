<?php

namespace Splicewire\Beam\Ux\Compile;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **compile port** (ADR-0209 §7) — raw entry source in, an executable ES module out.
 *
 * Compilation is deliberately a port rather than a concrete class: the default
 * ({@see NodeEntryBodyCompiler}) shells out to Node, which is a real deploy-topology commitment, and a
 * host with its own build service, a warm worker, or a format beam-ux has never heard of re-binds this
 * one interface instead of reimplementing the action, the store, the command and the doctor check that
 * sit on top of it.
 *
 * **A failure throws.** There is no "compiled to nothing" return and no degraded mode: ADR-0209 §7 rules
 * out a silent client-compile fallback, because degrading to shipping an MDX compiler to the browser is
 * an invisible regression that surfaces months later in someone's performance audit. Every consumer of
 * this port therefore fails loudly, and the doctor reports the entries whose artifacts are missing or
 * stale before a reader ever hits one.
 */
interface EntryBodyCompiler
{
    /**
     * Compile one entry's raw source into an executable ES module.
     *
     * @param  BeamUxEntry  $entry  the entry being compiled — its `format` picks the compilation
     *                              strategy, and its slug/placement identify the source in diagnostics
     * @param  string  $source  the raw body text, already decoded from the particle by its codec
     * @return string the ES module text to store as the artifact
     *
     * @throws CompilationFailed
     */
    public function compile(BeamUxEntry $entry, string $source): string;

    /** Whether this compiler can handle an entry's format at all — asked before a batch run reports. */
    public function handles(BeamUxEntry $entry): bool;
}
