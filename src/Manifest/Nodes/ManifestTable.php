<?php

namespace Splicewire\Beam\Ux\Manifest\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * A Code Component from `@splicewire/beam-ux/site`: a declared manifest read from a contributor's JSON endpoint.
 * laravel-beam-mcp's docs page places it; declared here, where it renders, so a page holding blocks can place it
 * (EP-42 S1).
 */
#[NodeType('ManifestTable', description: 'A table of a manifest read from an endpoint.')]
final class ManifestTable extends Block
{
    public function category(): ?string
    {
        return 'html';
    }

    public function admitsChildCategories(): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    public static function attrsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoint' => ['type' => 'string', 'title' => 'Endpoint'],
            ],
            'required' => ['endpoint'],
        ];
    }
}
