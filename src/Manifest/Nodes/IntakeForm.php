<?php

namespace Splicewire\Beam\Ux\Manifest\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * EP-07d: a page form whose submissions land in the submissions ledger through laravel-beam's public intake door.
 * Sealed: the fields come from the intake schema its `captureKey` names (resolved by the server, never authored here).
 */
#[NodeType('IntakeForm', 'A form that sends its submissions to a public intake schema.')]
final class IntakeForm extends Block
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
        return [
            'type' => 'object',
            'properties' => [
                'captureKey' => ['type' => 'string', 'minLength' => 1, 'title' => 'Intake schema'],
                'submitLabel' => ['type' => 'string', 'title' => 'Submit label'],
                'successMessage' => ['type' => 'string', 'title' => 'Success message'],
            ],
            'required' => ['captureKey'],
            'additionalProperties' => false,
        ];
    }
}
