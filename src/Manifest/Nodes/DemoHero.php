<?php

namespace Splicewire\Beam\Ux\Manifest\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/** Packaged editor island: no props, children, or text slots. */
#[NodeType('DemoHero')]
final class DemoHero extends Block
{
    public function category(): ?string
    {
        return 'html';
    }

    public function admitsChildCategories(): ?array
    {
        return [];
    }

    public static function attrsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }
}
