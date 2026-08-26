<?php

namespace Splicewire\Beam\Ux\Storage;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;

/**
 * Resolves the {@see StorageDriver} (the beam-core port) for a {@see BeamUxEntry} by the charter S2
 * **precedence**, the same shape as {@see PlacementResolver}:
 *
 *   1. **per-entry `driver`** — the entry's own `driver_ref` (the S2 additive column) names a registered
 *      driver.
 *   2. **`namespace → driver` map** — matched by longest namespace prefix.
 *   3. **default** — the `Stacked(Particle-primary, Disk-mirror)` the host binds under {@see self::DEFAULT}.
 *
 * beam-ux is the SECOND consumer of the generalized-from-BeamSchemaRegistry storage seam (the schema
 * registry is the first); this resolver is where beam-ux selects a storage driver per entry.
 *
 * ## Registry AND resolver (registry-kernel ticket 38)
 *
 * `beam.ux.storage-drivers` is the keyspace: `$this->entries`, registrant-supplied at boot. As on
 * {@see PlacementResolver}, `$namespaceMap` is host config pointing INTO that keyspace rather than a
 * second array over it, and the precedence rule that reads it stays in {@see resolve()}.
 *
 * @implements Registry<StorageDriver>
 */
#[IsRegistry(
    root: 'beam.ux.storage-drivers',
    of: 'StorageDriver implementations by name — where a BeamUxEntry particle body is read/written',
    arity: RegistryArity::PickOne,
    entryType: StorageDriver::class,
    onDuplicate: OnDuplicate::Supersede,
    order: 47,
)]
class StorageDriverResolver implements Gated, Registry
{
    public const DEFAULT = 'default';

    /** @var BasicRegistry<StorageDriver> registered drivers by name */
    protected BasicRegistry $entries;

    /** @var array<string, string> namespace(-prefix) → driver name */
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

    /** @param array<string, string> $map namespace-prefix → driver name */
    public function mapNamespaces(array $map): static
    {
        $this->namespaceMap = $map;

        return $this;
    }

    /**
     * The charter S2 precedence when handed an entry; the kernel's keyed lookup otherwise. See
     * {@see PlacementResolver::resolve()} for why the entry branch is widened INTO the contract.
     *
     * @return StorageDriver
     */
    public function resolve(RegistryKey|string|BeamUxEntry $key): mixed
    {
        if (! $key instanceof BeamUxEntry) {
            /** @var StorageDriver */
            return $this->entries->resolve($key);
        }

        $ref = $key->getAttribute('driver_ref');
        if (is_string($ref) && $ref !== '') {
            return $this->driver($ref);
        }

        $matched = $this->matchNamespace(is_string($key->namespace) ? $key->namespace : null);
        if ($matched !== null) {
            return $this->driver($matched);
        }

        return $this->driver(self::DEFAULT);
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
     * The registered driver names, as callers spelled them (keys relative in, absolute out).
     *
     * @return array<int, string>
     */
    public function drivers(): array
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

    protected function driver(string $name): StorageDriver
    {
        /** @var StorageDriver */
        return $this->entries->resolve($name);
    }
}
