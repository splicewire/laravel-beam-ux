<?php

namespace Splicewire\Beam\Ux\Compile;

use RuntimeException;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * A body that would not compile (ADR-0209 §7). Loud by construction: this is the exception type that
 * exists instead of a fallback, so the three producers all fail the same visible way and no path anywhere
 * can quietly decide to compile in the reader's browser instead.
 */
class CompilationFailed extends RuntimeException
{
    public static function for(BeamUxEntry $entry, string $reason): self
    {
        $format = $entry->format?->value ?? 'unknown';

        return new self(
            "Compiling entry [{$entry->slug}] (format: {$format}) failed: {$reason}"
        );
    }

    /** No registered strategy for the entry's format — a configuration gap, not a broken body. */
    public static function unsupported(BeamUxEntry $entry): self
    {
        $format = $entry->format?->value ?? 'unknown';

        return new self(
            "No compile strategy for entry [{$entry->slug}]'s format [{$format}]. Register one on the ".
            'bound '.EntryBodyCompiler::class.', or give the entry a format the default Node compiler handles.'
        );
    }
}
