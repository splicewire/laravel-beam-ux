<?php

namespace Splicewire\Beam\Ux\Preview;

use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;

interface PreviewGrantResolver
{
    public function resolve(string $token): ?BeamUxPreviewGrant;
}
