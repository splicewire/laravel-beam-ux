<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\Frame\Attributes\WidgetIn;
use Schemastud\Frame\Data\SummaryResponseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Dashboard\DashboardParticipation;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;

/**
 * One row of a realm's `{realm}-dashboard` resource — the read projection {@see DashboardBacking}
 * streams (realm-dashboards ticket 04), matching `@schemastud/frame`'s `DashboardRow` (ticket 03).
 *
 * `context` says what the row IS, and it is the only discriminator:
 *
 *  - `summary` / `overview` — one of the realm's resources compressed to figures, or expanded to a
 *    card with a body: `resource`, `navOrder` and `summary` are set. The client draws it through the
 *    TARGET resource's root entry for that context.
 *  - `nav` — a jump-to tile drawn from the realm's nav manifest: `label`, `icon`, `href` and an
 *    optional `description`; no `summary`, no manifest lookup.
 *  - `welcome` — the ONE row of a dashboard that would otherwise have none for this viewer: the
 *    first-run / nothing-yet panel, carried in `welcome` ({@see DashboardWelcomeData}). `label` is its
 *    heading, `href` is empty (the panel is not a door; its `actions` are), no `resource`, no `summary`.
 *    It never sits beside a card or a tile — {@see DashboardBacking} emits it only in their absence.
 *
 * ## Bound to `list-item`, class-level
 *
 * The dashboard is a plain frame list whose rows render as cards. The class-level `list-item` binding
 * names the `dashboard-card` widget, which dispatches on `context` — so a resource's own declaration
 * decides how its card looks, and this row never carries a widget name of its own.
 *
 * `summary` deliberately carries no `href`: frame's summary socket is realm-blind. The href on this row is
 * stamped by the realm-aware backing from the realm's router leaves.
 */
#[TypeScript]
#[WidgetIn('list-item', 'dashboard-card')]
class DashboardCardRowData extends BeamData
{
    public const CONTEXT_SUMMARY = DashboardParticipation::CONTEXT_SUMMARY;

    public const CONTEXT_OVERVIEW = DashboardParticipation::CONTEXT_OVERVIEW;

    public const CONTEXT_NAV = 'nav';

    public const CONTEXT_WELCOME = 'welcome';

    /**
     * @param  string  $id  `{context}:{resource}` for a card, `nav:{routeName|href}` for a tile, `welcome` for the welcome row — unique within the realm's page
     * @param  'summary'|'overview'|'nav'|'welcome'  $context  the render context the row is drawn in
     * @param  int|null  $navOrder  a card: the resource's declared nav order, null sorts last; a tile: its index in the rail's walk
     * @param  string|null  $resource  the summarized resource's key; null on a tile
     */
    public function __construct(
        public string $id,
        public string $context,
        public string $label,
        public ?string $icon,
        public string $href,
        public ?int $navOrder = null,
        public ?string $resource = null,
        public ?SummaryResponseData $summary = null,
        public ?string $description = null,
        public ?DashboardWelcomeData $welcome = null,
    ) {}

    public function isTile(): bool
    {
        return $this->context === self::CONTEXT_NAV;
    }

    public function isWelcome(): bool
    {
        return $this->context === self::CONTEXT_WELCOME;
    }
}
