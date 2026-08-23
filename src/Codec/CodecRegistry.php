<?php

namespace Splicewire\Beam\Ux\Codec;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

use InvalidArgumentException;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Dispatches a {@see BodyCodec} on a {@see UxFormatCase} (ADR-0164) — the "one codec per format" seam.
 * A host registers a codec per format it supports; the {@see BeamUxEntry}
 * resolves its codec by its own `format`. Genuinely open (not just documented as open, see
 * {@see UxFormatCase}): a host's own bespoke enum implementing `UxFormatCase` registers here exactly
 * like {@see \Splicewire\Beam\Ux\Format\UxFormat}'s own cases do — the registry keys on the plain
 * string value, never the concrete enum class.
 *
 * The default binding (registered by {@see BeamUxServiceProvider}) seeds the TSX
 * and MDX codecs.
 */
#[IsRegistry(
    root: 'beam.ux.codecs',
    of: 'BodyCodec implementations by UxFormat — how a BeamUxEntry body compiles to/from its raw source',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    order: 45,
)]
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
    public function for(UxFormatCase|string $format): BodyCodec
    {
        $key = $format instanceof UxFormatCase ? $format->value : $format;

        if (! isset($this->codecs[$key])) {
            throw new InvalidArgumentException("No BodyCodec registered for format [{$key}].");
        }

        return $this->codecs[$key];
    }

    public function has(UxFormatCase|string $format): bool
    {
        $key = $format instanceof UxFormatCase ? $format->value : $format;

        return isset($this->codecs[$key]);
    }

    /** @return array<int, string> the registered format values */
    public function formats(): array
    {
        return array_keys($this->codecs);
    }
}
