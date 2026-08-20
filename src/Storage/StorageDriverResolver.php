<?php

namespace Splicewire\Beam\Ux\Storage;

use InvalidArgumentException;
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
 */
class StorageDriverResolver
{
    public const DEFAULT = 'default';

    /** @var array<string, StorageDriver> registered drivers by name */
    protected array $drivers = [];

    /** @var array<string, string> namespace(-prefix) → driver name */
    protected array $namespaceMap = [];

    public function register(string $name, StorageDriver $driver): static
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    /** @param array<string, string> $map namespace-prefix → driver name */
    public function mapNamespaces(array $map): static
    {
        $this->namespaceMap = $map;

        return $this;
    }

    public function resolve(BeamUxEntry $entry): StorageDriver
    {
        $ref = $entry->getAttribute('driver_ref');
        if (is_string($ref) && $ref !== '') {
            return $this->driver($ref);
        }

        $matched = $this->matchNamespace(is_string($entry->namespace) ? $entry->namespace : null);
        if ($matched !== null) {
            return $this->driver($matched);
        }

        return $this->driver(self::DEFAULT);
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
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("No StorageDriver registered as [{$name}].");
        }

        return $this->drivers[$name];
    }
}
