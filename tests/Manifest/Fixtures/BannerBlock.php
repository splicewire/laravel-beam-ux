<?php

namespace Splicewire\Beam\Ux\Tests\Manifest\Fixtures;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/** A second host component, placeable only in the realm whose source declares it (EP-07 realm-bind tests). */
#[NodeType('Banner', 'An operator-realm banner.')]
class BannerBlock extends Block
{
    public function category(): ?string
    {
        return 'html';
    }

    public function admitsChildCategories(): ?array
    {
        return [];
    }
}
