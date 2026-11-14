<?php

namespace Splicewire\Beam\Ux\Compile;

use RuntimeException;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryError;

/**
 * A body that would not compile (ADR-0209 §7). Loud by construction: this is the exception type that
 * exists instead of a fallback, so the three producers all fail the same visible way and no path anywhere
 * can quietly decide to compile in the reader's browser instead.
 *
 * Localization 23A (range review B10 U4; RecordingFailure's pattern, ruling (a)): it carries its coded case and
 * parameters. Its own message stays the raw declared English, for logs. The `reason` parameter is the raw engine reason:
 * data, never translated. A response emit renders {@see display()}: the case's localized message, or the raw message
 * when it was built from a string.
 */
class CompilationFailed extends RuntimeException
{
    /** The coded case, or null for a message passed in as a string. */
    public ?CodedError $error = null;

    /** @var array<string, string> the case's parameters, passed through */
    public array $params = [];

    public static function for(BeamUxEntry $entry, string $reason): self
    {
        return self::coded(EntryError::CompileFailed, ['slug' => (string) $entry->slug, 'format' => $entry->format?->value ?? 'unknown', 'reason' => $reason]);
    }

    /** No registered strategy for the entry's format — a configuration gap, not a broken body. */
    public static function unsupported(BeamUxEntry $entry): self
    {
        return self::coded(EntryError::CompileUnsupported, ['slug' => (string) $entry->slug, 'format' => $entry->format?->value ?? 'unknown', 'compiler' => EntryBodyCompiler::class]);
    }

    /** The response representation: the case's localized message with its parameters, or the raw message. */
    public function display(): string
    {
        return $this->error?->message($this->params) ?? $this->getMessage();
    }

    /** @param  array<string, string>  $params */
    private static function coded(CodedError $error, array $params): self
    {
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements[':'.$name] = $value;
        }
        $exception = new self(strtr($error->defaultMessage(), $replacements));
        $exception->error = $error;
        $exception->params = $params;

        return $exception;
    }
}
