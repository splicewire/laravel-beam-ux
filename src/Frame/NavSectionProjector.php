<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavNode;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Throwable;

/**
 * Projects the top-level nav SEATS a package declared ({@see NavSection}, `beam.nav.sections`) into
 * the {@see InvocableNavItem} nodes a navigation is made of — the second half of a seam whose first
 * half deliberately carries no `Rushing\DataNav` type.
 *
 * ## Why the declaration lives one package down
 *
 * The packages that need to declare a seat — `laravel-beam-calendars`, `-notifications`,
 * `-workflows` — require ONLY `splicewire/laravel-beam`. They have neither beam-ux nor data-nav. So
 * `NavSection` is a plain value object in beam, and the translation to a nav node happens HERE, in
 * the one package that already depends on data-nav, frame and beam at once. A package declares in
 * vocabulary it has; this turns it into vocabulary it does not.
 *
 * ## What a seat is, and what it is not
 *
 * A seat is a section HEADER that auto-attaches the resources declaring `section:` matching its key
 * — the {@see FrameResourcesInvocable} does the attaching. The seat carries the label, icon, href
 * and gate vocabulary; it never carries children of its own. `routeName` is always `<key>.section`,
 * which is the spelling {@see RouteContextValidator} exempts from leaf binding, so a seat can never
 * be the thing that makes a manifest throw.
 *
 * ## Ordering is a preference, not a claim
 *
 * `NavSection::$order` orders the declared seats among THEMSELVES. It cannot order them against a
 * host's own hand-written sections, because a host that registers its own navigation supersedes this
 * projection wholesale — `NavRegistry` is `PickOne`/`Supersede` and host providers boot last. That
 * is the documented override seam and it is preserved by doing nothing.
 *
 * ## Every node is stamped `contributed`
 *
 * The stamp goes in the `#[Hidden]` meta bag, so it is server-only and never reaches the wire. It
 * exists so {@see FrameNavContribution} can tell a node a PACKAGE contributed from one the HOST
 * spelled out, and apply the unbound-route rule that differs between them — see the pruning
 * docblock there. Provenance is the whole reason the two can be treated differently at all.
 *
 * ## This is also where a SOFT-gated seat becomes a locked node
 *
 * `Rushing\DataNav\NavLocked`'s own docblock says the field *"is POPULATED by beam's manifest
 * projection… this DTO + `NavNode::locked()` only make that state expressible and serialized"*. This
 * is that projection: the one place in beam that turns a declared seat into a nav node, and so the
 * only place that can decide hard→absence vs soft→locked without moving the decision into
 * `rushing/laravel-data-nav`.
 *
 * ### Why the producer cannot be a gate STAGE, which is the obvious guess
 *
 * data-nav ships a three-way `NavVerdictStage` and a `NavGate::apply()` that folds a lock onto a
 * node. Neither is reachable from a built navigation: `NavRegistry::build()` gates through
 * `NavGate::allows()`, not `apply()` (`NavRegistry::gateExpand()`), and `allows()` deliberately
 * counts a lock as ALLOWED and returns a bare `bool` — so a verdict stage's lock is evaluated,
 * discarded, and never stamped on anything. `SoftGateProjectionTest` pins that binary reading on
 * purpose. A stage therefore CANNOT produce a visible lock, and making it able to would mean
 * switching `build()` to `apply()` — moving the projection into the package whose own docblock says
 * beam owns it.
 *
 * Producing the lock HERE needs none of that. A soft seat is stamped before it is ever registered, so
 * the gate has nothing to deny (see {@see NavSection::gateAfterLock()}), and `locked` is a serialized
 * field, so it survives `build()`'s `toArray()`/`from()` active-stamping round-trip — the same
 * round-trip that erases the `#[Hidden]` meta bag two paragraphs up. Wire-visible is exactly the
 * difference between the two, and it is what makes this seam work where node-level provenance could
 * not.
 *
 * ### Which plane a lock speaks for
 *
 * The ENTITLEMENT plane only. A lock says *"your plan does not include this"*; the permission plane
 * says *"not for you"*, and a soft seat still carries its `permission` gate meta so that denial still
 * omits. A tenant may hold an entitlement while a user still lacks the permission, so conflating them
 * would show an upsell to someone whose organisation already pays.
 */
class NavSectionProjector
{
    /** The one top-level node the Developer zone's seats gather under (M4). */
    public const DEVELOPER_SEAT = 'developer.section';

    /** The `#[Hidden]` meta key marking a node as package-contributed rather than host-spelled. */
    public const CONTRIBUTED = 'beam.nav.contributed';

    /** The Gate ability prefix beam-core defines one of per known feature key (ADR-0013 §2/§4). */
    private const ENTITLEMENT_ABILITY = 'entitlement:';

    public function __construct(
        private NavSectionRegistry $sections,
        private Gate $gate,
        private ParticleResourceRegistry $particles,
        private ResourceVisibility $visibility,
        private SeatGate $seatGate,
        private RealmRegistry $realms,
        private Container $container,
    ) {}

    /**
     * The realm's dashboard leaf (when its `{realm}-dashboard` resource is registered and the principal
     * may list it) and the declared seats for the realm, in ONE ordering: {@see NavSection::compare()}'s
     * `[order, key]`, the leaf carrying its declaration's `navOrder` as its order. Kind is not a sort
     * input — a host seat declaring `order <= 0` precedes the dashboard, and that is the rule.
     *
     * A realm no package targeted yields no seats — not an error. "Which realms exist here" is a host
     * fact, and a package declaring a seat for a realm this host does not ship is a silent no-op,
     * exactly as an unmatched `RealmOverlay` is at projection time.
     *
     * `$context` is optional: a SOFT-gated seat reads it for its per-principal lock verdict, and the
     * dashboard leaf reads it for its visibility gate. Absent context means no principal — a guest holds
     * nothing and is never shown a model-less resource — so the parameter needs no caller to change.
     *
     * @return array<int, NavNode>
     */
    public function project(string $realm, ?NavContext $context = null): array
    {
        // Only walk the resource catalog when this realm HAS a dashboard — the leaf's href and the
        // static-row join below are the only two readers, and both are inert without one.
        $hrefs = $this->particles->has(RealmDashboard::keyFor($realm)) && $this->realms->tryResolve($realm) !== null
            ? $this->hrefs($realm)
            : [];

        /** @var list<array{0: int, 1: string, 2: NavNode}> $leaf */
        $leaf = $this->dashboardLeaf($realm, $context?->user, $hrefs);
        $leafHref = $leaf === [] ? null : $this->normalizeHref($leaf[0][2]->href ?? '');

        /** @var list<array{0: int, 1: string, 2: NavNode}> $entries */
        $entries = [];

        /** @var array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}|null $authored */
        $authored = null;

        foreach ($this->sections->for($realm) as $section) {
            $static = $this->withoutTheLeafsOwnRow($section->static, $leafHref, $hrefs, $authored);

            $entries[] = [$section->order, $section->key, $this->seat($section, $context?->user, $static), $section->audience];
        }

        if ($authored !== null) {
            $leaf[0][2] = $this->wearing($leaf[0][2], $authored);
        }

        $entries = [...$leaf, ...$entries];

        usort($entries, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $this->zoned($entries);
    }

    /**
     * The partition (ux-walkthrough M4, UX-08): every top-level node is drawn in the rail (`zone: primary`) except the
     * `developer` seats, which gather in their declared order under ONE last node, `developer.section`, drawn in the
     * Developer zone (`zone: meta`). The node is a group header, so the invariants read it as a label, and an empty one
     * is dropped with the other empty contributed seats.
     *
     * Each seat goes by its OWN declaration: two seats may share a key (a host's and a package's), and only the one
     * declared `developer` belongs in the Developer zone. The dashboard leaf carries no audience and is product.
     *
     * @param  list<array{0: int, 1: string, 2: NavNode, 3?: NavAudience}>  $entries  in projection order
     * @return array<int, NavNode>
     */
    private function zoned(array $entries): array
    {
        $primary = [];
        $meta = [];

        foreach ($entries as $entry) {
            $node = $entry[2];
            if (($entry[3] ?? NavAudience::Product) === NavAudience::Developer) {
                $meta[] = $node;
            } else {
                $primary[] = $node->inZone('primary');
            }
        }

        if ($meta !== []) {
            $primary[] = NavLink::make(title: 'Developer', icon: 'Code', routeName: self::DEVELOPER_SEAT)
                ->inZone('meta')
                ->stamped(active: false, activeTrail: false, children: $meta);
        }

        return $primary;
    }

    /**
     * A seat's hand-authored rows MINUS the one that lands on the realm dashboard's own href — and the
     * dropped row handed back through `$authored` so the leaf can wear its label and icon.
     *
     * ## Why one entry and not two
     *
     * A host that authors its rail from `resources/beam-ux/nav.yml` writes a row for its dashboard page
     * — the starter's `dashboard: {segment: /dashboard, realm: account}` — and that row becomes a static
     * child of whichever seat the host's own rail builder hands it to. Beam-ux ALSO emits the realm's
     * `{realm}-dashboard` leaf at the top level ({@see dashboardLeaf()}), and the two are the same
     * destination: measured at `beam.test` and `satellite.test`, the account rail carried
     * *Platform › Dashboard* beside *Dashboard*, both `/dashboard`.
     *
     * The de-duplication happens HERE rather than at either author. The host's `nav.yml` is a statement
     * about which pages exist and must not have to know that a package now seats one of them; the
     * `{realm}-dashboard` registration is a package fact and must not learn what a host authored. This
     * projector is the one place that sees both, which is the same reason the seat translation lives
     * here at all.
     *
     * ## The survivor is the LEAF, wearing the authored label and icon
     *
     * The generated leaf wins its position (its declaration's `navOrder`, ahead of the seats) because
     * that placement is the realm-dashboard decision (ticket 04) and a row nested under a seat is the
     * thing being corrected. But the authored `title`/`icon` are the host's own words about its own
     * page, so they ride onto the leaf when they differ from what the resource declared — a host that
     * called it "Home" keeps "Home", and one that wrote nothing keeps the leaf's defaults.
     *
     * ## Identity is `routeName` when the row names one, and the href when it does not
     *
     * {@see FrameResourcesInvocable::staticChildren()} derives a static's rendered href as
     * `$hrefs[$routeName] ?? $declared`, so those are exactly the two questions worth asking. A
     * nav.yml-derived row carries no `routeName` (a name the routeContext cannot bind would be pruned),
     * which is why the href arm is the live one; the routeName arm covers a host that spells the
     * dashboard leaf out by name.
     *
     * @param  list<array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}>  $static
     * @param  array<string, string>  $hrefs  routeName => leaf href, from {@see RouteContextProjector::hrefs()}
     * @param  array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}|null  $authored
     * @return list<array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}>
     */
    private function withoutTheLeafsOwnRow(array $static, ?string $leafHref, array $hrefs, ?array &$authored): array
    {
        if ($leafHref === null || $static === []) {
            return $static;
        }

        $kept = [];

        foreach ($static as $row) {
            $routeName = $row['routeName'] ?? null;
            $href = ($routeName !== null ? $hrefs[$routeName] ?? null : null) ?? ($row['href'] ?? '');

            if ($this->normalizeHref($href) !== $leafHref) {
                $kept[] = $row;

                continue;
            }

            // First one wins: two seats both claiming the dashboard is a host defect, and taking the
            // first keeps the outcome deterministic (the registry's order) rather than last-write.
            $authored ??= $row;
        }

        return $kept;
    }

    /**
     * The leaf carrying an authored row's title and icon where it has them — a clone, on the same
     * shape as {@see NavNode::stamped()}/{@see NavNode::locked()}, so the meta bag and the lock survive.
     *
     * @param  array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}  $authored
     */
    private function wearing(NavNode $leaf, array $authored): NavNode
    {
        $clone = clone $leaf;

        if (($authored['title'] ?? '') !== '') {
            $clone->title = $authored['title'];
        }

        if (($authored['icon'] ?? null) !== null && $authored['icon'] !== '') {
            $clone->icon = $authored['icon'];
        }

        return $clone;
    }

    /** One spelling for a client path, so `/dashboard`, `dashboard` and `/dashboard/` compare equal. */
    private function normalizeHref(string $href): string
    {
        return '/'.trim($href, '/');
    }

    /**
     * The realm's `routeName => href` map, or an empty one where it cannot be built. A host that has
     * bound no frame {@see ResourceRegistry}, or a realm whose router will not project, is a host fact
     * and yields no join rather than a fatal — the same decline {@see FrameNavContribution} makes.
     *
     * @return array<string, string>
     */
    private function hrefs(string $realm): array
    {
        try {
            return $this->container->bound(ResourceRegistry::class)
                ? $this->container->make(RouteContextProjector::class)->hrefs($realm)
                : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The section-less, realm-level leaf for the realm's dashboard resource, as one `[order, key, node]`
     * entry of the projection — the one node this projection emits that is NOT a seat (realm-dashboards
     * ticket 04).
     *
     * ## Why a leaf and not a seat
     *
     * A seat is a header whose children the collector attaches by `section:`; the dashboard is a
     * destination with no children, and giving it a section would nest "the realm's landing" under a
     * header. Its ORDER is the declaration's `navOrder` (zero, as beam-ux registers it), entering the
     * same comparison as the seats rather than being prepended: the declaration says where it sits, and
     * a host that wants a seat ahead of it declares a lower order.
     *
     * ## Gated like a resource, bound like a leaf
     *
     * The node exists only for a principal {@see ResourceVisibility::listable()} admits to the dashboard
     * resource — the same question the collector asks of every resource child, so the rail and the
     * dashboard's own read gate cannot disagree (a null principal is never shown a model-less resource).
     * Its `routeName` is the resource's declared list route, which {@see RouteContextProjector} emits as
     * a `mounts: 'list'` leaf at `/{realmBase}/dashboard` — so {@see RouteContextValidator} finds it
     * bound. The href is read off that SAME projection rather than derived here; a realm whose router
     * cannot be projected (frame's registry port unbound) gets no leaf rather than an unjoinable one.
     *
     * @param  array<string, string>  $hrefs  routeName => leaf href, from {@see hrefs()}
     * @return list<array{0: int, 1: string, 2: NavNode}>
     */
    private function dashboardLeaf(string $realm, ?Authenticatable $user, array $hrefs): array
    {
        $key = RealmDashboard::keyFor($realm);

        if (! $this->particles->has($key) || $this->realms->tryResolve($realm) === null) {
            return [];
        }

        try {
            $definition = $this->particles->definition($key, $realm);

            $routeName = RealmDashboard::routeNameFor($realm);
            $resolution = $this->seatGate->resolve($routeName, $realm);

            if ($resolution === null
                ? ! $this->visibility->listable($definition, $user)
                : ! $this->seatGate->allows($resolution, $user)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $href = $hrefs[$routeName] ?? null;

        if ($href === null) {
            return [];
        }

        return [[
            $definition->nav->navOrder ?? 0,
            $key,
            NavLink::make(
                title: $definition->nav->label !== '' ? $definition->nav->label : RealmDashboard::LABEL,
                href: $href,
                match: trim($href, '/'),
                icon: $definition->nav->icon,
                routeName: $routeName,
            )->withMeta(array_filter([
                self::CONTRIBUTED => true,
                SeatGate::META => $resolution,
            ], fn (mixed $value): bool => $value !== null)),
        ]];
    }

    /**
     * One declared section as a nav node.
     *
     * The `input` mirrors what a host's own section helper passes, so the collector cannot tell a
     * declared seat from a hand-written one — which is the point: the attachment rules, the sort and
     * the `viewAny` gating are identical either way.
     *
     * The `static` rows are passed IN rather than read off the section, because
     * {@see withoutTheLeafsOwnRow()} has already removed any row that duplicates the realm's dashboard
     * leaf. Everything else about the seat is untouched.
     *
     * @param  list<array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}>  $static
     */
    private function seat(NavSection $section, ?Authenticatable $user, array $static): NavNode
    {
        $node = InvocableNavItem::make(
            title: $section->label,
            invocable: FrameResourcesInvocable::NAME,
            input: [
                'section' => $section->key,
                'realm' => $section->realm,
                'static' => $static,
            ],
            href: $section->href,
            match: trim($section->href, '/').'*',
            icon: $section->icon,
            routeName: $section->key.'.section',
        );

        // A soft-gated seat the principal cannot reach: keep it, stamped with the wire-visible lock,
        // and hand the gate a meta bag with the entitlement axis REMOVED — otherwise a host's
        // entitlement stage would omit the node this lock exists to keep visible.
        //
        // `gate()`/`gateAfterLock()` already drop a null and keep an empty array, so "declared and
        // unsatisfiable" stays distinguishable from "never declared" all the way to the gate stage.
        if ($section->isSoftGated() && ! $this->entitled($section, $user)) {
            return $node
                ->withMeta($section->gateAfterLock() + [self::CONTRIBUTED => true])
                ->locked($section->lock->reason, $section->lock->upsell);
        }

        return $node->withMeta($section->gate() + [self::CONTRIBUTED => true]);
    }

    /**
     * Whether the principal holds ANY of a seat's declared entitlement keys — the same any-of reading
     * the hard entitlement stage applies, asked through beam-core's `entitlement:{key}` Gate plane
     * (ADR-0013 §2/§4) rather than through a commerce type.
     *
     * ## Why it asks `has()` before `allows()`
     *
     * `Gate::allows()` on an UNDEFINED ability returns false — indistinguishable from a real denial,
     * and beam registers the `entitlement:*` abilities only when a host has bound an
     * `EntitlementResolver` (`BeamServiceProvider::registerEntitlementAbilities()` returns early
     * otherwise). Reading that false as "unentitled" would lock every soft seat at every host that
     * has no entitlement plane at all — turning a working section into a permanent upsell on a bare
     * install. `has()` is the instrument that tells "not configured" from "denied"; unconfigured is
     * INERT, so the seat projects unlocked and this whole addition stays byte-for-byte on a host that
     * has not opted in.
     *
     * A declared-but-EMPTY list (`entitlement: []`) is not that case: the loop simply finds no key to
     * satisfy and the seat locks, which is the "declared and admits nobody" reading `NavSection`
     * argues for.
     *
     * The Gate plane is also the reason no commerce class is named here. `Splicewire\Tower\Navigation\
     * Gates\EntitlementNavGateStage` constructor-injects a commerce `EntitlementGate` and type-checks
     * a `Beam\Tenancy\Tenant`, neither of which exists at a bare beam host — referencing it from a
     * projector every beam host loads would fail container resolution at nav-build time.
     */
    private function entitled(NavSection $section, ?Authenticatable $user): bool
    {
        foreach ($section->entitlement ?? [] as $key) {
            $ability = self::ENTITLEMENT_ABILITY.$key;

            if (! $this->gate->has($ability)) {
                return true; // the plane does not know this key here — not configured, not denied
            }

            if ($this->gate->forUser($user)->allows($ability)) {
                return true; // any-of: one held key reveals the seat, unlocked
            }
        }

        return false;
    }
}
