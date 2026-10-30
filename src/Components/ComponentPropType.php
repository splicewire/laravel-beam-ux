<?php

namespace Splicewire\Beam\Ux\Components;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;

enum ComponentPropType: string implements ProvidesEnumLabel
{
    case Text = 'text';
    case Number = 'number';
    case Toggle = 'toggle';
    case Choice = 'choice';
    case Link = 'link';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Toggle => 'Toggle',
            self::Choice => 'Choice',
            self::Link => 'Link',
            self::Image => 'Image',
        };
    }
}
