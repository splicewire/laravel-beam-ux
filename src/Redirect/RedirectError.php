<?php

namespace Splicewire\Beam\Ux\Redirect;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a Redirect was refused (localization 23; ticket 13's pattern). The English is byte-identical to the literal the
 * refusal carried before. `:path` is the requested path, which the person typed.
 */
enum RedirectError: string implements CodedError
{
    use IsCodedError;

    #[Message('A Redirect starts from a path on this site, like /old-page.')]
    case PathRequired = 'redirect_path_required';

    #[Message('The site never receives :path, so a Redirect there could never answer.')]
    case PathNeverReceived = 'redirect_path_never_received';

    #[Message('A page answers :path, and a page always wins.')]
    case PathIsPage = 'redirect_path_is_page';

    #[Message('A Redirect already starts from :path.')]
    case PathTaken = 'redirect_path_taken';

    #[Message('Name a path on this site, like /new-page.')]
    case TargetPathRequired = 'redirect_target_path_required';

    #[Message('This Redirect would lead back to itself.')]
    case TargetLoops = 'redirect_target_loops';

    #[Message('No page has that URL. Name the page by its current URL.')]
    case TargetPageUnknown = 'redirect_target_page_unknown';

    #[Message('Name a Record as collection:id.')]
    case TargetRecordFormat = 'redirect_target_record_format';

    #[Message('Name an address on another site, starting https://.')]
    case TargetExternalHttps = 'redirect_target_external_https';
}
