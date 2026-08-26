<?php

namespace Splicewire\Beam\Ux\Codec;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
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
 *
 * ## On the Popcorn kernel (registry-kernel ticket 38)
 *
 * The store is a composed {@see BasicRegistry}, never a base class, and `beam.ux.codecs` is described
 * into the {@see \Rushing\Popcorn\Registries\RegistryIndex} from {@see BeamUxServiceProvider}'s boot
 * chain — beam-ux's whole binding surface lives in `Concerns\Wires*` traits, so the describe sits in
 * the trait that owns the fill ({@see \Splicewire\Beam\Ux\Concerns\WiresCodecs}), not in a hand-written
 * provider block. {@see for()} and {@see formats()} stay as the vocabulary consumers already speak.
 *
 * @implements Registry<BodyCodec>
 */
#[IsRegistry(
    root: 'beam.ux.codecs',
    of: 'BodyCodec implementations by UxFormat — how a BeamUxEntry body compiles to/from its raw source',
    arity: RegistryArity::PickOne,
    entryType: BodyCodec::class,
    onDuplicate: OnDuplicate::Supersede,
    order: 45,
)]
class CodecRegistry implements Gated, Registry
{
    /** @var BasicRegistry<BodyCodec> */
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register a codec under its own format value.
     *
     * WIDENED from the contract rather than shadowing it — contravariance, the same self-keying
     * one-argument door {@see \Rushing\Codegen\GeneratorRegistry::register()} opens, so every historical
     * `register(new TsxBodyCodec)` caller keeps working alongside a contract `register($key, $entry)`.
     */
    public function register(RegistryKey|string|BodyCodec $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof BodyCodec) {
            $entry = $key;
            $key = $key->format()->value;
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * Resolve the codec for a format, or throw when none is registered.
     *
     * Sugar over {@see resolve()}, kept because it is what every call site in this package says. The
     * miss is now the kernel's `RegistryMiss` (a `RuntimeException`) rather than a package-local
     * `InvalidArgumentException`.
     */
    public function for(UxFormatCase|string $format): BodyCodec
    {
        /** @var BodyCodec */
        return $this->resolve($format instanceof UxFormatCase ? $format->value : $format);
    }

    public function has(RegistryKey|string|UxFormatCase $key): bool
    {
        return $this->entries->has($key instanceof UxFormatCase ? $key->value : $key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The registered format values, as callers spelled them — {@see keys()} with the declared root
     * stripped back off, because keys go relative in and absolute out (ticket 20 D2).
     *
     * @return array<int, string> the registered format values
     */
    public function formats(): array
    {
        return $this->entries->relativeKeys();
    }
}
