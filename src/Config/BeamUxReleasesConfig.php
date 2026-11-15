<?php

namespace Splicewire\Beam\Ux\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * `beam.ux.releases` (ADR-0226: review policy is the host's). `review_required` is read per request, when a Release is
 * requested and when it is projected (`ReleaseService::requestOne()`, `awaitsReview()`; `ReleaseData`), so it is
 * declared `live` and overridable up to the operator tier. Its package default stays OFF (the owner's ruling,
 * `5dd27af98`). The other keys keep the default declaration: deploy-only, set in the host file or env.
 */
class BeamUxReleasesConfig extends Data
{
    public function __construct(
        #[Title('Require review before publishing'), Description('When on, a Publish is submitted for review instead of going live at once.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live, env: 'BEAM_UX_RELEASES_REVIEW_REQUIRED')]
        public bool $review_required,
        #[Title('Allow self-approval'), Description('Whether the person who requested a Release may approve it.')]
        public bool $allow_self_approval,
        #[Title('Edit ability'), Description('The ability checked to edit a Release; empty falls back to the entry policy.')]
        public ?string $edit_ability,
        #[Title('Publish ability'), Description('The ability checked to publish a Release; empty falls back to the entry policy.')]
        public ?string $publish_ability,
    ) {}
}
