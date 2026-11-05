<?php

namespace Splicewire\Beam\Ux\Nav;

use Splicewire\Beam\Ux\Nav\Contracts\NavItemSource;

/**
 * The shipped default behind {@see NavItemSource}: a host with no record leaf persists no nav items, so there is
 * nothing to orphan. Bound with `bindIf`; a host's own source supersedes it with a plain `bind()`.
 */
class NoNavItemSource implements NavItemSource
{
    public function navItems(): iterable
    {
        return [];
    }
}
