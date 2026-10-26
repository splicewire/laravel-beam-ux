<?php

namespace Splicewire\Beam\Ux\Manifest\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * A Code Component from `@splicewire/beam-ux/site`: the site's nav, or the subtree under `rootPath`. laravel-beam-docs'
 * docs page places it; declared here, where it renders, so a page holding blocks can place it (EP-42 S1).
 */
#[NodeType('SiteNav', description: 'The site\'s navigation, or the pages under one path.')]
final class SiteNav extends Block
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
                'rootPath' => ['type' => 'string', 'title' => 'Under path'],
                'maxDepth' => ['type' => 'integer', 'title' => 'Levels', 'minimum' => 1],
            ],
        ];
    }
}
