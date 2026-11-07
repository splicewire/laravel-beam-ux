<?php

namespace Splicewire\Beam\Ux\Frame;

use Rushing\DataNav\NavContext;
use Schemastud\Frame\Contracts\FrameNavContributor;

/**
 * A {@see FrameNavContributor} that can say what context its nav gate stages are given for a realm, without building the
 * tree. {@see SectionOverviewGate} asks it, so an overview's admission and the rail read one context.
 *
 * Not a port a host binds: {@see FrameNavContribution} implements it over its existing overridable `context()`, so every
 * contributor extending that class has it by inheritance. Null means the context cannot be had, and the overview refuses.
 */
interface ProvidesNavContext
{
    public function navContextFor(string $realm): ?NavContext;
}
