<?php

namespace Splicewire\Beam\Ux\Manifest\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/** ADR-0229 d13: closed prose element mapping, never a host code-node renderer. */
#[NodeType('RichBlockdoc')]
final class RichBlockdoc extends Block
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
        return ['type' => 'object', 'properties' => ['content' => ['type' => ['object', 'null'], 'format' => 'blockdoc']],
            'required' => ['content'], 'additionalProperties' => false];
    }
}
