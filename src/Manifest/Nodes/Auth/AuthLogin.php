<?php

namespace Splicewire\Beam\Ux\Manifest\Nodes\Auth;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/** Shipped auth-page island (`@splicewire/beam-inertia`'s registry): sealed, with no props, children or text slots. */
#[NodeType('AuthLogin')]
final class AuthLogin extends Block
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
