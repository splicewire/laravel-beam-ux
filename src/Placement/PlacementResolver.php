<?php

namespace Splicewire\Beam\Ux\Placement;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Resolves the {@see FilePlacement} strategy for an entry by the charter S2 **precedence**:
 *
 *   1. **per-entry ref** — the entry's own `placement_ref` (the S2 additive column) names a registered
 *      strategy; the most specific wins.
 *   2. **per-namespace map** — a `namespace → strategy-name` map (config `beam.ux.placement.namespaces`)
 *      matched by longest namespace prefix, so `articles.*` can be date-partitioned wholesale.
 *   3. **default** — the {@see DefaultPlacement} (`namespace/type/slug.ext`).
 *
 * Strategies are registered by NAME (like the {@see CodecRegistry}), so a host
 * adds a placement without editing this class. The resolver is beam-ux's; the disk it derives a path
 * *for* is beam-core's {@see StorageDriver}.
 *
 * ## Registry AND resolver, and the two are not the same array (registry-kernel ticket 38)
 *
 * `beam.ux.placements` is the keyspace: `$this->entries`, registrant-supplied at boot, addressable by
 * name. `$namespaceMap` is **not** a second array over that keyspace and is deliberately left out of
 * it — it is a `namespace-prefix → strategy-name` indirection table pointing INTO the keyspace, host
 * config rather than registry entries, and the precedence rule that reads it lives in
 * {@see resolve()}. Collapsing it into the registry would erase the rule.
 *
 * {@see resolve()} therefore answers two questions through one contravariantly widened signature: given
 * a {@see BeamUxEntry} it runs the precedence above; given a key it is the kernel's lookup.
 *
 * @implements Registry<FilePlacement>
 */
#[IsRegistry(
    root: 'beam.ux.placements',
    of: 'FilePlacement strategies by name — the disk mirror path an entry materializes to',
    arity: RegistryArity::PickOne,
    entryType: FilePlacement::class,
    onDuplicate: OnDuplicate::Supersede,
    order: 46,
)]
class PlacementResolver implements Gated, Registry
{
    public const DEFAULT = 'default';

    /** @var BasicRegistry<FilePlacement> registered strategies by name */
    protected BasicRegistry $entries;

    /** @var array<string, string> namespace(-prefix) → strategy name */
    protected array $namespaceMap = [];

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /** @param array<string, string> $map namespace-prefix → strategy name */
    public function mapNamespaces(array $map): static
    {
        $this->namespaceMap = $map;

        return $this;
    }

    /**
     * The charter S2 precedence when handed an entry; the kernel's keyed lookup otherwise.
     *
     * The `BeamUxEntry` branch is WIDENED into the contract rather than living beside it under another
     * name: `resolve($entry)` is what every call site in this package (and its ADR-0165 prose) already
     * says, and the contract's `resolve($key)` answers the same question about the same keyspace one
     * step lower down.
     *
     * @return FilePlacement
     */
    public function resolve(RegistryKey|string|BeamUxEntry $key): mixed
    {
        if (! $key instanceof BeamUxEntry) {
            /** @var FilePlacement */
            return $this->entries->resolve($key);
        }

        // 1. per-entry ref — the most specific.
        $ref = $key->getAttribute('placement_ref');
        if (is_string($ref) && $ref !== '') {
            return $this->strategy($ref);
        }

        // 2. per-namespace map — longest matching prefix wins.
        $matched = $this->matchNamespace(is_string($key->namespace) ? $key->namespace : null);
        if ($matched !== null) {
            return $this->strategy($matched);
        }

        // 3. default.
        return $this->strategy(self::DEFAULT);
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
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
     * The registered strategy names, as callers spelled them (keys relative in, absolute out).
     *
     * @return array<int, string>
     */
    public function strategies(): array
    {
        return $this->entries->relativeKeys();
    }

    protected function matchNamespace(?string $namespace): ?string
    {
        if ($namespace === null || $this->namespaceMap === []) {
            return null;
        }

        $best = null;
        $bestLen = -1;
        foreach ($this->namespaceMap as $prefix => $name) {
            if (($namespace === $prefix || str_starts_with($namespace, $prefix.'.')) && strlen($prefix) > $bestLen) {
                $best = $name;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    protected function strategy(string $name): FilePlacement
    {
        /** @var FilePlacement */
        return $this->entries->resolve($name);
    }
}
