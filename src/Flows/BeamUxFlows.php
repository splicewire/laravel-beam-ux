<?php

namespace Splicewire\Beam\Ux\Flows;

use Splicewire\Beam\Flows\DeclaresFlows;
use Splicewire\Beam\Flows\FlowDeclaration;
use Splicewire\Beam\Flows\Gates\AlwaysOn;
use Splicewire\Beam\Flows\ListsFlows;

/**
 * beam-ux's flow declarations (browser-journeys 08; config-sections 11, lead BJ-1310). This package owns the Release
 * panel, a surface every host renders in each realm, so the flow is declared HERE, once, not by a host.
 *
 * - `releases` is the surface. A surface names no routes.
 * - `releases.panel` is the Release panel (members by kind, the config key diff, the one-commit file list, the workspace
 *   database-history line). ONE key for the screen; the realm is a role (`operator`, `tenant`), never part of the key, so
 *   its screenshots are `releases.panel--operator@1280.png` and `releases.panel--tenant@1280.png`. Its routes are `[]`:
 *   the panel is a host-rendered Frame page whose route names differ per host and realm.
 * - Gate {@see AlwaysOn}: no runtime switch turns the panel off. Who may open it is authorization (`ux.author`), which is
 *   not a flow gate.
 *
 * Pure and static ({@see DeclaresFlows}); `BeamUxServiceProvider` declares it to the catalog inside its registrant scope.
 * Checkpoints are `null` (ticket 04's Checkpoint contract lands with browser-journeys 16). No browser test is written
 * yet: the helper's signature freezes when browser-journeys 10 lands.
 */
final class BeamUxFlows implements DeclaresFlows
{
    use ListsFlows;

    public static function flows(): array
    {
        return [
            new FlowDeclaration('releases', 'Releases', [], AlwaysOn::class, null, []),
            new FlowDeclaration('releases.panel', 'Release panel', [], AlwaysOn::class, null, ['operator', 'tenant']),
        ];
    }
}
