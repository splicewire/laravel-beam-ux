<?php

namespace Splicewire\Beam\Ux\Enums;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;

enum DashboardWelcomeState: string implements ProvidesEnumLabel
{
    case FirstRun = 'first-run';

    case Empty = 'empty';

    public function label(): string
    {
        return match ($this) {
            self::FirstRun => 'First run',
            self::Empty => 'Empty',
        };
    }
}
