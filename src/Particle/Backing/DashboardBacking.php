<?php

namespace Splicewire\Beam\Ux\Particle\Backing;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use ReflectionClass;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Registry\WidgetContextProjector;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Registry\ResourceRegistryBacking;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Data\DashboardCardRowData;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\RealmDashboard;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;
use Throwable;

/**
 * One realm's dashboard as a resource — the cards of the realm's nav-seated resources, then the realm's
 * nav manifest drawn as jump-to tiles, one {@see DashboardCardRowData} each (realm-dashboards ticket 04,
 * executing the otb-ui-frontier-sidebar DESIGN-01 ruling that a dashboard is a read-only resource).
 *
 * The template is {@see ResourceRegistryBacking}: model-less, streams-only, actor-filtered, in-memory
 * cursor. It carries the REALM, and that is why it is an instance backing: frame's resource socket is
 * mounted once and realm-blind, so the realm cannot be read off the request — it is derived from the
 * dashboard resource's own membership, which is exactly one realm.
 *
 * ## Which resources become cards
 *
 * For every registered resource in the realm ({@see ParticleResourceRegistry::keysForRealm()}), in this
 * order, each a reason to DROP the row rather than throw:
 *
 *  1. the actor may not LIST it ({@see ResourceVisibility::listable()} — `viewAny` for a model-backed
 *     resource, the declared read gate for a model-less one, never a null actor);
 *  2. its read Data class declares `#[Summary(false)]` — the opt-out;
 *  3. it is neither nav-SEATED in this realm (declares `section:` and a package seated that section
 *     here, {@see NavSectionRegistry}) nor explicitly opted in (declares `summary` or `overview`);
 *  4. the host does not mount its list route (its `routeName` names no leaf in the realm's router
 *     projection) — a package cannot 500 a host's dashboard by naming a resource the host never placed;
 *  5. its summary provider declines, or names no provider — an honest absence, never an invented zero.
 *
 * Default participation is decided HERE, not by frame: a realm resource participates iff it is seated,
 * unless it opts out. `overview` is opt-in, and a card whose resource declares one renders in that
 * context; otherwise `summary`.
 *
 * ## Two gates, both written down
 *
 * The dashboard's OWN read gate is the realm gate's ability, declared as its `policy:`
 * ({@see RealmDashboard::abilityFor()}); frame's access gate asks it before this backing ever runs. The
 * SECOND gate is per row, above: a card exists only for a resource the actor may be shown in the rail,
 * so the dashboard cannot become the bypass around the visibility that hides a resource from the nav.
 * The realm gate is not re-asked per row — every row is a resource of the same realm the actor already
 * opened.
 *
 * ## One sort, one page
 *
 * Cards sort once by `navOrder` (nulls last) then label, exactly as the rail does; tiles follow every card.
 * The page is deliberately UNPAGED: a dashboard is one screen, its population is bounded by the realm's
 * resource count, and the request's `perPage` is ignored so no card ever lands on a second page.
 *
 * ## Hrefs come from the router projection, not from the key
 *
 * A card's `href` is the leaf its resource's `routeName` names in {@see RouteContextProjector::hrefs()}
 * — the ONE derivation {@see FrameResourcesInvocable} also reads — so the card and the rail seat cannot
 * point at two different URLs. The summary payload itself carries no href (frame's socket is realm-blind).
 */
class DashboardBacking implements ResolvesRecord, StreamsRecords
{
    public function __construct(
        public readonly string $realm,
    ) {}

    /**
     * Every row, one page. `$filters` is ignored (the resource is not filterable) and `$perPage` is
     * overridden by the row count — see the class docblock. A cursor is accepted only so the contract is
     * honoured: any cursor restarts the single page.
     */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $rows = $this->rows();

        return new CursorPaginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        foreach ($this->rows() as $row) {
            if ($row->id === $id) {
                return new ResolvedRecord(record: $row);
            }
        }

        return null;
    }

    /**
     * The realm's card rows then its tile rows, in the one sorted order.
     *
     * ⚠️ Deliberately NOT memoized on this object. Unlike a class-string backing, which the resolver
     * constructs per request, this INSTANCE lives inside the registry's declaration for the life of the
     * process — a memo here would serve the first actor's rows to every later request (a long-running
     * worker, or one test's staff actor to the next test's member). Every call reads the actor afresh.
     *
     * @return list<DashboardCardRowData>
     */
    public function rows(): array
    {
        $container = Container::getInstance();

        if ($container->make(RealmRegistry::class)->tryResolve($this->realm) === null) {
            return [];
        }

        $rows = [...$this->cards($container), ...$this->tiles($container)];

        // ONE sort: tiles after every card, then navOrder (undeclared trails), then label. Stable, so
        // rows tied on all three keep their build order.
        usort($rows, fn (DashboardCardRowData $a, DashboardCardRowData $b): int => [
            $a->isTile(),
            $a->navOrder ?? PHP_INT_MAX,
            mb_strtolower($a->label),
        ] <=> [
            $b->isTile(),
            $b->navOrder ?? PHP_INT_MAX,
            mb_strtolower($b->label),
        ]);

        return $rows;
    }

    /**
     * @return list<DashboardCardRowData>
     */
    private function cards(Container $container): array
    {
        $particles = $container->make(ParticleResourceRegistry::class);
        $visibility = $container->make(ResourceVisibility::class);
        $actor = $this->actor($container);
        $hrefs = $this->hrefs($container);
        $seats = array_map(
            fn (NavSection $section): string => $section->key,
            $container->make(NavSectionRegistry::class)->for($this->realm),
        );

        $cards = [];

        foreach ($particles->keysForRealm($this->realm) as $key) {
            if (RealmDashboard::isKey($key, $this->realm) || ! $particles->find($key)?->isFramed()) {
                continue;
            }

            try {
                $definition = $particles->definition($key, $this->realm);
            } catch (Throwable) {
                continue; // a declaration this request cannot project is a row it must not show
            }

            if (! $visibility->listable($definition, $actor)) {
                continue;
            }

            $context = $this->contextFor($definition, $seats);

            if ($context === null) {
                continue;
            }

            $href = $hrefs[$definition->nav->routeName ?? $key.'.index'] ?? null;

            if ($href === null) {
                continue; // the host mounts no list route for it — drop, never throw
            }

            $summary = $this->summarize($container, $definition);

            if ($summary === null) {
                continue;
            }

            $cards[] = new DashboardCardRowData(
                id: $context.':'.$key,
                context: $context,
                label: $summary->label,
                icon: $summary->icon ?? $definition->nav->icon,
                href: $href,
                navOrder: $definition->nav->navOrder,
                resource: $key,
                summary: $summary,
            );
        }

        return $cards;
    }

    /**
     * The context a resource's card renders in, or null when the resource is not on this realm's
     * dashboard: `overview` when it declares one, `summary` when it declares one or is nav-seated here;
     * null on `#[Summary(false)]`, on an undeclared unseated resource, and on a Data class whose
     * declarations frame's projector rejects.
     *
     * @param  list<string>  $seats  the section keys seated in this realm
     * @return 'summary'|'overview'|null
     */
    private function contextFor(ResourceDefinition $definition, array $seats): ?string
    {
        try {
            $contexts = (new WidgetContextProjector)->forClass(new ReflectionClass($definition->data));
        } catch (Throwable) {
            return null;
        }

        $summary = $contexts[DashboardCardRowData::CONTEXT_SUMMARY] ?? null;
        $overview = $contexts[DashboardCardRowData::CONTEXT_OVERVIEW] ?? null;

        if ($summary !== null && ($summary['participates'] ?? true) === false) {
            return null;
        }

        if ($overview !== null && ($overview['participates'] ?? true) !== false) {
            return DashboardCardRowData::CONTEXT_OVERVIEW;
        }

        $seated = $definition->nav->section !== null && in_array($definition->nav->section, $seats, true);

        return $summary !== null || $seated ? DashboardCardRowData::CONTEXT_SUMMARY : null;
    }

    /**
     * The resource's summary through its declared provider — resolved exactly as frame's
     * {@see \Schemastud\Frame\Summary\ResourceSummaries} resolves it, minus the HTTP aborts: a resource
     * naming no provider, a provider that declines, or one that throws is a dropped row. A throwing
     * provider is reported, not swallowed silently — the dashboard stays up and the host's log says why a
     * card is missing.
     */
    private function summarize(Container $container, ResourceDefinition $definition): ?SummaryResponseData
    {
        if ($definition->summaryProvider === null) {
            return null;
        }

        try {
            $provider = $container->make($definition->summaryProvider);

            return $provider instanceof ResourceSummaryProvider ? $provider->summary($definition) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The realm's nav manifest as tiles — every LEAF (a node with an href and no children) of the
     * contributed, gated, pruned navigation, minus the dashboard's own leaf. The seats themselves are
     * headers, not destinations, so they draw no tile. Read through the same contributor the manifest
     * uses, so a tile exists exactly where a rail entry does for this actor.
     *
     * @return list<DashboardCardRowData>
     */
    private function tiles(Container $container): array
    {
        try {
            $nav = $container->make(FrameNavContribution::class)->contributeNav($this->realm);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $tiles = [];
        $own = RealmDashboard::routeNameFor($this->realm);

        $walk = function (array $nodes) use (&$walk, &$tiles, $own): void {
            foreach ($nodes as $node) {
                $children = is_array($node['children'] ?? null) ? $node['children'] : [];

                if ($children !== []) {
                    $walk($children);

                    continue;
                }

                $href = $node['href'] ?? null;
                $routeName = $node['routeName'] ?? null;

                if (! is_string($href) || $href === '' || $routeName === $own) {
                    continue;
                }

                $tiles[] = new DashboardCardRowData(
                    id: DashboardCardRowData::CONTEXT_NAV.':'.($routeName ?? $href),
                    context: DashboardCardRowData::CONTEXT_NAV,
                    label: (string) ($node['title'] ?? $href),
                    icon: is_string($node['icon'] ?? null) ? $node['icon'] : null,
                    href: $href,
                );
            }
        };

        $walk($nav['nav']['items'] ?? []);

        return $tiles;
    }

    /**
     * `routeName => href` for the realm's leaves — the same join {@see FrameResourcesInvocable} reads. An
     * empty map when the projection cannot be built (frame's registry port unbound at a host that never
     * mounts the manifest): every card then drops as unmounted, which is the honest reading.
     *
     * @return array<string, string>
     */
    private function hrefs(Container $container): array
    {
        try {
            return $container->make(RouteContextProjector::class)->hrefs($this->realm);
        } catch (Throwable) {
            return [];
        }
    }

    private function actor(Container $container): ?Authenticatable
    {
        $actor = $container->make(ActorPort::class)->actor();

        return $actor instanceof Authenticatable ? $actor : null;
    }
}
