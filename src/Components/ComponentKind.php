<?php

namespace Splicewire\Beam\Ux\Components;

/** Null on existing rows means source; the body never chooses its own execution policy. */
enum ComponentKind: string
{
    case Authored = 'authored';
    case Source = 'source';
}
