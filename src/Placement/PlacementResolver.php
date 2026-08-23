<?php

namespace Splicewire\Beam\Ux\Placement;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

use InvalidArgumentException;
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
 */
#[IsRegistry(
    root: 'beam.ux.placements',
    of: 'FilePlacement strategies by name — the disk mirror path an entry materializes to',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    order: 46,
)]
class PlacementResolver
{
    public const DEFAULT = 'default';

    /** @var array<string, FilePlacement> registered strategies by name */
    protected array $strategies = [];

    /** @var array<string, string> namespace(-prefix) → strategy name */
    protected array $namespaceMap = [];

    public function register(string $name, FilePlacement $strategy): static
    {
        $this->strategies[$name] = $strategy;

        return $this;
    }

    /** @param array<string, string> $map namespace-prefix → strategy name */
    public function mapNamespaces(array $map): static
    {
        $this->namespaceMap = $map;

        return $this;
    }

    public function resolve(BeamUxEntry $entry): FilePlacement
    {
        // 1. per-entry ref — the most specific.
        $ref = $entry->getAttribute('placement_ref');
        if (is_string($ref) && $ref !== '') {
            return $this->strategy($ref);
        }

        // 2. per-namespace map — longest matching prefix wins.
        $matched = $this->matchNamespace(is_string($entry->namespace) ? $entry->namespace : null);
        if ($matched !== null) {
            return $this->strategy($matched);
        }

        // 3. default.
        return $this->strategy(self::DEFAULT);
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
        if (! isset($this->strategies[$name])) {
            throw new InvalidArgumentException("No FilePlacement strategy registered as [{$name}].");
        }

        return $this->strategies[$name];
    }
}
