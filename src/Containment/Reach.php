<?php

namespace Splicewire\Beam\Ux\Containment;

use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Data\ReachData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;

/**
 * **Reach**: the live pages an entry changes (ADR-0232 d10, and ADR-0231 d16's "PricingCard — used on 12 pages").
 *
 * - A page reaches itself.
 * - A layout or template reaches every page whose containment chain resolves to it ({@see ChromeResolver}: the nearest
 *   declaring page wins), by its slug.
 * - An Authored Component reaches every page that renders it, directly or through another component, layout or
 *   template.
 * - A theme reaches every page.
 *
 * Read in the tree a Preview shows when one is given (its Release's versions and page settings, its Take-downs gone),
 * else the live tree. A page counts when it is live, or the Preview's Release will publish it. Asking about several
 * entries, build one {@see ReachMap} with map() and ask it each time.
 */
final class Reach
{
    public function __construct(private AuthoredComponents $components) {}

    public function of(BeamUxEntry $entry, ?PreviewContext $preview = null): ReachData
    {
        return $this->map((string) $entry->realm, $entry->getConnectionName(), $preview)->of($entry);
    }

    public function map(string $realm, ?string $connection, ?PreviewContext $preview = null): ReachMap
    {
        return new ReachMap($realm, $connection, $preview, $this->components);
    }
}
