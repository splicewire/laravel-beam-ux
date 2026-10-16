<?php

namespace Splicewire\Beam\Ux\Tests\Manifest\Fixtures;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * A host-registered placeable component for EP-07a (b)-2 tests — a `#[NodeType]` Block the resolver
 * composes onto the HTML base through a bound ManifestNodeSource.
 */
#[NodeType('Callout', 'A host-registered callout component.')]
class CalloutBlock extends Block
{
    public function category(): ?string
    {
        return 'html';
    }

    public function admitsChildCategories(): ?array
    {
        return ['html'];
    }
}
