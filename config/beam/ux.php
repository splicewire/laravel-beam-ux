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

    /*
    |--------------------------------------------------------------------------
    | Puck codegen (ADR-0164 — the "NEXT" slice)
    |--------------------------------------------------------------------------
    |
    | `blocks_module` — the JS module the CODEGEN'd `.tsx` imports its block
    |                   components from. A Puck `page`'s body is a Puck `Data`
    |                   document; on Publish the PlacedDiskMirror compiles it to
    |                   a composed-JSX React file whose import line is
    |                   `import { Heading, … } from '<blocks_module>'`. The block
    |                   vocabulary is the HOST's (satellite-local), so this points
    |                   at wherever the host exports it. Default matches a Vite
    |                   `@`-alias'd `resources/js/puck/blocks`.
    |
    */
    'puck' => [
        'blocks_module' => env('BEAM_UX_PUCK_BLOCKS_MODULE', '@/puck/blocks'),

        /*
        | The Node PuckData ⟷ BlockDoc bridge (ticket 08 — the reverse .tsx→body leg).
        |
        | `script` — absolute path to `bin/puck-bridge.mjs` in the resolved `@splicewire/beam-ux`
        |            package (e.g. `<app>/node_modules/@splicewire/beam-ux/bin/puck-bridge.mjs`).
        |            Null/unset ⇒ the reverse page-sync is UNAVAILABLE (degrade-not-fabricate): a
        |            composed page `.tsx` is never re-encoded as raw component source.
        | `node`   — the node interpreter (resolved on PATH; default `node`).
        | `timeout`— the per-invocation process timeout, seconds.
        |
        | Lossless JSX↔PuckData parsing needs the JS toolchain (recast + babel), so the parse runs in
        | Node, not PHP. `splicewire:beam:ux-sync-from-disk` + `register-from-disk` shell to this CLI.
        */
        'bridge' => [
            // Defaults to the CLI in the host's resolved `@splicewire/beam-ux` node_modules — the
            // host-agnostic path that "just works" once the JS package is installed + built. Override
            // via env for a non-standard layout; unset ⇒ degrade (the reverse page-sync is a no-op).
            'script' => env('BEAM_UX_PUCK_BRIDGE_SCRIPT', base_path('node_modules/@splicewire/beam-ux/bin/puck-bridge.mjs')),
            'node' => env('BEAM_UX_PUCK_BRIDGE_NODE', 'node'),
            'timeout' => (int) env('BEAM_UX_PUCK_BRIDGE_TIMEOUT', 30),
        ],
    ],

];
