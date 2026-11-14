<?php

namespace Splicewire\Beam\Ux\Analytics;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/** Why an analytics read or a Click Event was refused (localization 23; ticket 13's pattern; English byte-identical). */
enum AnalyticsError: string implements CodedError
{
    use IsCodedError;

    #[Message("The Click Event [:name] is not in this Manifest Profile's Event Vocabulary.")]
    case ClickEventUnknown = 'analytics_click_event_unknown';

    #[Message('The Site area reads each page as its viewer in a realm; with no signed-in viewer or realm it cannot be read.')]
    case SiteAreaNeedsViewer = 'analytics_site_area_needs_viewer';
}
