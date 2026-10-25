<?php

namespace Splicewire\Beam\Ux\Preview;

use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;

final readonly class PreviewGrant
{
    public function __construct(
        public string $token,
        public BeamUxPreviewGrant $record,
    ) {}
}
