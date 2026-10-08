<?php

namespace Splicewire\Beam\Ux\Frame;

use Rushing\DataNav\Contracts\NavGateStage;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavNode;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Authorization\SeatGateResolution;

/** Applies the route-derived gate a projector attached to a nav node's hidden metadata. */
class SeatGateNavGateStage implements NavGateStage
{
    public function __construct(private SeatGate $gates) {}

    public function allows(NavNode $node, NavContext $context): bool
    {
        $resolution = $node->meta[SeatGate::META] ?? null;

        return ! $resolution instanceof SeatGateResolution
            || $this->gates->allows($resolution, $context->user);
    }
}
