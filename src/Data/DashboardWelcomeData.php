<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\Backing\DashboardWelcome;

/**
 * The first-run / nothing-yet panel a realm dashboard draws when it has no card and no tile for the
 * viewer — the payload of a {@see DashboardCardRowData} whose `context` is `welcome`, built by
 * {@see DashboardWelcome}.
 *
 * ## Two states, and the copy is the server's
 *
 *  - `first-run` — the viewer is on no team, so the realm has nothing it could show them. The heading
 *    greets them by name.
 *  - `empty` — the viewer is on a team (or the team question has no answer at this host) and there is
 *    simply nothing to show yet. Softer copy, no claim about teams.
 *
 * The strings are written here, server-side, because the facts behind them are: whether the viewer
 * belongs to a team, and which next steps the HOST actually mounts. The client renders what it is
 * given and invents no affordance.
 *
 * `actions` holds only destinations the host routes (see {@see DashboardWelcome} for each condition);
 * `hint` is a line of guidance with no destination of its own, null when it would promise a flow the
 * host does not have.
 */
#[TypeScript]
class DashboardWelcomeData extends BeamData
{
    public const STATE_FIRST_RUN = 'first-run';

    public const STATE_EMPTY = 'empty';

    /**
     * @param  'first-run'|'empty'  $state  which of the two panels this is
     * @param  list<DashboardWelcomeActionData>  $actions  next steps, in display order; only routes the host mounts
     */
    public function __construct(
        public string $state,
        public string $heading,
        public string $body,
        /** @var list<DashboardWelcomeActionData> */
        public array $actions = [],
        public ?string $hint = null,
    ) {}
}
