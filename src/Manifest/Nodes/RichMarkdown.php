<?php

namespace Splicewire\Beam\Ux\Manifest\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/** ADR-0229 d13: package-owned element renderer, never an MDX or raw HTML island. */
#[NodeType('RichMarkdown')]
final class RichMarkdown extends Block
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
        return ['type' => 'object', 'properties' => ['content' => ['type' => ['string', 'null'], 'format' => 'markdown']],
            'required' => ['content'], 'additionalProperties' => false];
    }
}
