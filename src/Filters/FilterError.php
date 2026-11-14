<?php

namespace Splicewire\Beam\Ux\Filters;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/** Why a projected filter value was refused (localization 23; ticket 13's pattern; English byte-identical). */
enum FilterError: string implements CodedError
{
    use IsCodedError;

    #[Message('The filter value has an invalid type.')]
    case ValueInvalid = 'filter_value_invalid';
}
