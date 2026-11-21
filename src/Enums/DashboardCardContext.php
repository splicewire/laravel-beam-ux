<?php

namespace Splicewire\Beam\Ux\Enums;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;

enum DashboardCardContext: string implements ProvidesEnumLabel
{
    case Summary = 'summary';

    case Overview = 'overview';

    case Nav = 'nav';

    case Welcome = 'welcome';

    public function label(): string
    {
        return match ($this) {
            self::Summary => 'Summary',
            self::Overview => 'Overview',
            self::Nav => 'Navigation',
            self::Welcome => 'Welcome',
        };
    }
}
