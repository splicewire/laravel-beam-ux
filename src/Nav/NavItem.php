<?php

namespace Splicewire\Beam\Ux\Nav;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Ux\Doctor\OrphanedNavItemAudit;
use Splicewire\Beam\Ux\Nav\Contracts\NavItemSource;

/**
 * One nav leaf: the shape a host's nav renders over and the projection target its leaf kinds resolve to. A
 * content-derived leaf yields one per doc; a record leaf projects each owner-edited row into one. Frame owns no
 * router, so the host renders its nav by mapping over these: this is the host's join shape.
 *
 * `external` marks an off-host `href`, the affordance a content-derived nav structurally cannot express (a doc glob
 * only knows in-repo slugs). {@see OrphanedNavItemAudit} skips external items: an off-host target is not the app's
 * to validate.
 *
 * Packaged from the five starters' identical `App\Sitemap\NavItem` (make-surface 01). A host hands its record leaf's
 * items to the audit through {@see NavItemSource}.
 */
class NavItem extends Data
{
    public function __construct(
        public string $label,
        public string $href,
        public int $order = 0,
        public bool $external = false,
    ) {}
}
