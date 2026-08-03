<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entry-body authoring API root (ADR-0124 owner-tier seam)
    |--------------------------------------------------------------------------
    |
    | The owner-tier URI prefix + route-name stem the HOST mounts the entry-body
    | authoring endpoints at via `Route::beamUxEntries()`, resolved client-side by
    | the `beam.ux.entries.body.*` route names. beam-ux is a `Splicewire\Beam\*`
    | package → the free `/beam` tier, domain `ux`, so the defaults are `beam/ux`
    | (→ `GET|PUT beam/ux/entries/{slug}/body`) and the `beam.ux.` name stem.
    |
    | Config-driven (not hardcoded in the macro) so a host can relocate the mount
    | per-deploy via env — or by passing explicit args to the macro — without a
    | code change. The package ships the default + the (policy-free) controller;
    | the host owns the mount, the auth middleware (which IS the write gate), and
    | the wire.
    |
    */
    'api_root' => env('BEAM_UX_API_ROOT', 'beam/ux'),

    'route_name' => env('BEAM_UX_ROUTE_NAME', 'beam.ux.'),

    /*
    |--------------------------------------------------------------------------
    | Storage (ADR-0165 S2 — the disk seam)
    |--------------------------------------------------------------------------
    |
    | `disk`         — the filesystem disk the DEFAULT Stacked(Particle, Disk)
    |                  driver mirrors to, keyed by particle id. Null ⇒ the
    |                  framework default disk.
    | `mirror_disk`  — the filesystem disk the placement-keyed PlacedDiskMirror
    |                  projects to on Publish, keyed by the paid FilePlacement
    |                  path (`{namespace}/{type}/{slug}.{ext}`). This is the
    |                  human/git-facing projection — point it at a git-tracked
    |                  dev dir to version-control Puck pages. Null/unset ⇒ the
    |                  mirror is a no-op (degrade-not-fabricate).
    | `namespaces`   — namespace-prefix → driver-name map for the resolver.
    |
    */
    'storage' => [
        'disk' => env('BEAM_UX_STORAGE_DISK'),
        'mirror_disk' => env('BEAM_UX_STORAGE_MIRROR_DISK'),
    ],

];
