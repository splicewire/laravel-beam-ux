<?php

namespace Splicewire\Beam\Ux\Nav\Contracts;

use Splicewire\Beam\Ux\Doctor\OrphanedNavItemAudit;
use Splicewire\Beam\Ux\Nav\NavItem;
use Splicewire\Beam\Ux\Nav\NoNavItemSource;

/**
 * The owner-edited nav items a host persists: the record leaf whose links can rot in a way a content-derived leaf
 * cannot (an owner points an item at a path no route serves). {@see OrphanedNavItemAudit} validates them.
 *
 * The shipped default is {@see NoNavItemSource} (`bindIf`): a host with no record leaf has nothing to orphan. A host
 * with one binds its own source from its provider; the pair is order-independent by construction.
 *
 * `navItems()` may throw when the host's storage is absent (no table yet); the audit reads that as "no record-leaf
 * nav to validate", never as a failure.
 */
interface NavItemSource
{
    /**
     * Every persisted nav item, read fresh.
     *
     * @return iterable<NavItem>
     */
    public function navItems(): iterable;
}
