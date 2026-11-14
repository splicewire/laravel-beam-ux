<?php

namespace Splicewire\Beam\Ux\Intake;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why {@see IntakeForms} refused a form on a canvas body (localization 23A; range review B10 U2). The node's id and
 * path and the capture key it names are parameters, never key material. The English is byte-identical to the literal
 * the refusal carried before.
 */
enum IntakeError: string implements CodedError
{
    use IsCodedError;

    #[Message('node at [:path] posts to [:key], which is not a public intake schema on this host')]
    case FormTargetNotPublic = 'intake_form_target_not_public';

    #[Message('node #:id at [:path] posts to [:key], which is not a public intake schema on this host')]
    case FormTargetNotPublicIdentified = 'intake_form_target_not_public_identified';
}
