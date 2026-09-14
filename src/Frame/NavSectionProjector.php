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
use Splicewire\Beam\Dashboard\RealmDashboard;
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
    /** The `#[Hidden]` meta key marking a node as package-contributed rather than host-spelled. */
    public const CONTRIBUTED = 'beam.nav.contributed';

    /** The Gate ability prefix beam-core defines one of per known feature key (ADR-0013 §2/§4). */
    private const ENTITLEMENT_ABILITY = 'entitlement:';

    public function __construct(
        private NavSectionRegistry $sections,
        private Gate $gate,
        private ParticleResourceRegistry $particles,
        private ResourceVisibility $visibility,
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
        /** @var list<array{0: int, 1: string, 2: NavNode}> $entries */
        $entries = $this->dashboardLeaf($realm, $context?->user);

        foreach ($this->sections->for($realm) as $section) {
            $entries[] = [$section->order, $section->key, $this->seat($section, $context?->user)];
        }

        usort($entries, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $entry): NavNode => $entry[2], $entries);
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
     * @return list<array{0: int, 1: string, 2: NavNode}>
     */
    private function dashboardLeaf(string $realm, ?Authenticatable $user): array
    {
        $key = RealmDashboard::keyFor($realm);

        if (! $this->particles->has($key) || $this->realms->tryResolve($realm) === null) {
            return [];
        }

        try {
            $definition = $this->particles->definition($key, $realm);

            if (! $this->visibility->listable($definition, $user)) {
                return [];
            }

            $hrefs = $this->container->bound(ResourceRegistry::class)
                ? $this->container->make(RouteContextProjector::class)->hrefs($realm)
                : [];
        } catch (Throwable) {
            return [];
        }

        $routeName = RealmDashboard::routeNameFor($realm);
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
            )->withMeta([self::CONTRIBUTED => true]),
        ]];
    }

    /**
     * One declared section as a nav node.
     *
     * The `input` mirrors what a host's own section helper passes, so the collector cannot tell a
     * declared seat from a hand-written one — which is the point: the attachment rules, the sort and
     * the `viewAny` gating are identical either way.
     */
    private function seat(NavSection $section, ?Authenticatable $user): NavNode
    {
        $node = InvocableNavItem::make(
            title: $section->label,
            invocable: FrameResourcesInvocable::NAME,
            input: [
                'section' => $section->key,
                'realm' => $section->realm,
                'static' => $section->static,
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
