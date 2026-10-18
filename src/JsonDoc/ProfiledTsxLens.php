<?php

namespace Splicewire\Beam\Ux\JsonDoc;

/** Optional manifest-aware lowering; existing host TsxLens implementations remain compatible. */
interface ProfiledTsxLens extends TsxLens
{
    public function lowerWithProfile(string $source, array $profile): array;
}
