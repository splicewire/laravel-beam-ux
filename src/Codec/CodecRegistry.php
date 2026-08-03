<?php

namespace Splicewire\Beam\Ux\Codec;

use InvalidArgumentException;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Dispatches a {@see BodyCodec} on a {@see UxFormat} (ADR-0164) — the "one codec per format" seam. A
 * host registers a codec per format it supports; the {@see BeamUxEntry}
 * resolves its codec by its own `format`. Because the registry keys on the string value, a host can
 * register a codec for a format the enum does not yet name (the "`tsx | mdx | …`" open set).
 *
 * The default binding (registered by {@see BeamUxServiceProvider}) seeds the TSX
 * and MDX codecs.
 */
class CodecRegistry
{
    /** @var array<string, BodyCodec> keyed by format value */
    protected array $codecs = [];

    public function register(BodyCodec $codec): static
    {
        $this->codecs[$codec->format()->value] = $codec;

        return $this;
    }

    /** Resolve the codec for a format, or throw when none is registered. */
    public function for(UxFormat|string $format): BodyCodec
    {
        $key = $format instanceof UxFormat ? $format->value : $format;

        if (! isset($this->codecs[$key])) {
            throw new InvalidArgumentException("No BodyCodec registered for format [{$key}].");
        }

        return $this->codecs[$key];
    }

    public function has(UxFormat|string $format): bool
    {
        $key = $format instanceof UxFormat ? $format->value : $format;

        return isset($this->codecs[$key]);
    }

    /** @return array<int, string> the registered format values */
    public function formats(): array
    {
        return array_keys($this->codecs);
    }
}
