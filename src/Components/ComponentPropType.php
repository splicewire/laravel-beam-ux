<?php

namespace Splicewire\Beam\Ux\Components;

enum ComponentPropType: string
{
    case Text = 'text';
    case Number = 'number';
    case Toggle = 'toggle';
    case Choice = 'choice';
    case Link = 'link';
    case Image = 'image';
}
