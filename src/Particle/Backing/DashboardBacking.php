<?php

namespace Splicewire\Beam\Ux\Particle\Backing;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\CursorPaginator;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\AuthenticatedActor;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Dashboard\DashboardParticipation;
use Splicewire\Beam\Dashboard\RailLeaves;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Particle\Backing\Unpaged;
use Splicewire\Beam\Particle\ListRouteName;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Registry\ResourceRegistryBacking;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Data\DashboardCardRowData;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;
use Throwable;

/**
 * One realm's dashboard as a resource — the cards of the realm's rail-seated resources, then the realm's
 * rail drawn as jump-to tiles, one {@see DashboardCardRowData} each (realm-dashboards ticket 04,
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
 *  2. the host does not mount its list route (its route name names no leaf in the realm's router
 *     projection) — a package cannot 500 a host's dashboard by naming a resource the host never placed;
 *  3. it is not ON the dashboard ({@see DashboardParticipation::contextFor()} — the ONE rule, shared with
 *     beam's `DashboardTierAudit`): a leaf of the realm's rail resolves to it, or it declares
 *     `summary`/`overview`; `#[Summary(false)]` opts out;
 *  4. its summary provider declines, or names no provider — an honest absence, never an invented zero.
 *
 * "The rail" is the PROJECTED navigation for this actor — `FrameNavContribution::contributeNav()`, the
 * tree the rail renders — so a resource a host seats through a section's static children (the beam
 * starter's `users`/`teams`, which declare no `section:`) is on the dashboard exactly when it is in the
 * rail. The same walk yields the tiles, so a card and a tile cannot disagree about what the rail holds.
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
 * Cards sort once by `navOrder` (nulls last) then label, exactly as the rail does; tiles follow every card
 * in the rail's own walk order (a tile's `navOrder` IS its rail index). A card whose resource declares NO
 * `navOrder` takes the rail index of the leaf that admitted it, so the cards read in the rail's order too
 * — the reference host's `users`/`teams` declare none, and a label fallback read them `teams, users` while
 * their own tiles read `Users, Teams`. The page is {@see Unpaged}: a
 * dashboard is one screen, its population is bounded by the realm's resource count, and the handler
 * envelopes it so neither the request's `perPage` nor frame's `per_page` can land a card on a second page.
 *
 * ## No detail, by declaration
 *
 * The resource is `showable: false` and this backing resolves nothing: a card is a projection of ANOTHER
 * resource, whose own list is the place to open it. The handler refuses the detail read on the flag.
 *
 * ## Hrefs come from the router projection, not from the key
 *
 * A card's `href` is the leaf its resource's list route name names in {@see RouteContextProjector::hrefs()}
 * — the ONE derivation {@see FrameResourcesInvocable} also reads — so the card and the rail seat cannot
 * point at two different URLs. The summary payload itself carries no href (frame's socket is realm-blind).
 */
class DashboardBacking implements Unpaged
{
    public function __construct(
        public readonly string $realm,
    ) {}

    /**
     * Every row, one page. `$filters` is ignored (the resource is not filterable) and `$perPage` by the
     * {@see Unpaged} contract. A cursor is accepted only so the contract is honoured: any cursor restarts
     * the single page.
     */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $rows = $this->rows();

        return new CursorPaginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
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

        $rail = $this->rail($container);
        $rows = [...$this->cards($container, $rail), ...$this->tiles($rail)];

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
    private function cards(Container $container, RailLeaves $rail): array
    {
        $particles = $container->make(ParticleResourceRegistry::class);
        $visibility = $container->make(ResourceVisibility::class);
        $actor = $this->actor($container);
        $hrefs = $this->hrefs($container);

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

            $href = $hrefs[ListRouteName::of($definition)] ?? null;

            if ($href === null) {
                continue; // the host mounts no list route for it — drop, never throw
            }

            $context = DashboardParticipation::contextFor($definition, $rail, $href, $seat);

            if ($context === null) {
                continue;
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
                // A declared `navOrder` wins. With none declared, the card takes the index of the rail
                // leaf that admitted it — the SAME leaf the tile is drawn from — so a card and its tile
                // cannot read in two different orders. Null only for a resource on the dashboard by
                // declaration alone, which sits in no leaf and therefore has no rail position.
                navOrder: $definition->nav->navOrder ?? $seat?->index,
                resource: $key,
                summary: $summary,
            );
        }

        return $cards;
    }

    /**
     * The resource's summary through its declared provider — resolved exactly as frame's
     * {@see \Schemastud\Frame\Summary\ResourceSummaries} resolves it, minus the HTTP aborts: a resource
     * naming no provider, a provider that declines, or one that throws is a dropped row. A throwing
     * provider is reported, not swallowed silently — the dashboard stays up and the host's log says why a
     * card is missing.
     *
     * "Stays up" includes the request's open database transactions. On PostgreSQL a failed statement
     * aborts the enclosing transaction, so a provider whose query throws, caught here, used to leave every
     * later query of the request refused with `25P02 current transaction is aborted` — the dashboard 500ed
     * on the NEXT card or gate check instead of dropping one row. Measured at laravel-tower-starter, whose
     * operator rail seats `conduits`, a tenant table its central test schema does not carry. Each provider
     * therefore runs inside a savepoint on every connection that already has a transaction open, and a
     * throw rolls back to it. With no transaction open (an ordinary request) nothing is issued.
     */
    private function summarize(Container $container, ResourceDefinition $definition): ?SummaryResponseData
    {
        if ($definition->summaryProvider === null) {
            return null;
        }

        try {
            $provider = $container->make($definition->summaryProvider);

            if (! $provider instanceof ResourceSummaryProvider) {
                return null;
            }

            return $this->withinSavepoints($container, fn (): ?SummaryResponseData => $provider->summary($definition));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withinSavepoints(Container $container, Closure $callback): mixed
    {
        $open = array_values(array_filter(
            $container->make('db')->getConnections(),
            fn (ConnectionInterface $connection): bool => $connection->transactionLevel() > 0,
        ));

        foreach ($open as $connection) {
            $connection->beginTransaction();
        }

        try {
            $result = $callback();
        } catch (Throwable $e) {
            foreach (array_reverse($open) as $connection) {
                $connection->rollBack();
            }

            throw $e;
        }

        foreach (array_reverse($open) as $connection) {
            $connection->commit();
        }

        return $result;
    }

    /**
     * The realm's rail for this actor — every leaf of the contributed, gated, pruned navigation, in walk
     * order. Read through the same contributor the manifest uses, so a tile exists exactly where a rail
     * entry does, and a card's "seated" reading is the rail the actor sees. Empty when the realm has no
     * navigation or it cannot be built (reported, not thrown: the cards that declare themselves survive).
     */
    private function rail(Container $container): RailLeaves
    {
        try {
            $nav = $container->make(FrameNavContribution::class)->contributeNav($this->realm);
        } catch (Throwable $e) {
            report($e);

            return new RailLeaves([]);
        }

        return RailLeaves::fromNavItems($nav['nav']['items'] ?? []);
    }

    /**
     * The rail as tiles — every leaf minus the dashboard's own, `navOrder` stamped with the leaf's walk
     * index so the tiles read in the rail's order. The seats themselves are headers, not destinations,
     * so they draw no tile.
     *
     * @return list<DashboardCardRowData>
     */
    private function tiles(RailLeaves $rail): array
    {
        $own = RealmDashboard::routeNameFor($this->realm);
        $tiles = [];

        foreach ($rail->leaves as $leaf) {
            if ($leaf->routeName === $own) {
                continue;
            }

            $tiles[] = new DashboardCardRowData(
                id: DashboardCardRowData::CONTEXT_NAV.':'.($leaf->routeName ?? $leaf->href),
                context: DashboardCardRowData::CONTEXT_NAV,
                label: $leaf->title,
                icon: $leaf->icon,
                href: $leaf->href,
                navOrder: $leaf->index,
            );
        }

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
        return AuthenticatedActor::from($container->make(ActorPort::class));
    }
}
