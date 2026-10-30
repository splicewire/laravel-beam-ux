<?php

namespace Splicewire\Beam\Ux\Storage;

/** Resolves the optional scratch root shared by beam-ux's storage and schema artifacts. */
final class ScratchStorageRoot
{
    public static function resolve(bool $allowProcessEnvFallback = true): ?string
    {
        $root = config('beam.ux.scratch_storage_root');

        if ((! is_string($root) || $root === '') && $allowProcessEnvFallback) {
            $root = getenv('BEAM_SCRATCH_STORAGE_ROOT') ?: null;
        }

        return is_string($root) && $root !== '' ? $root : null;
    }
}
