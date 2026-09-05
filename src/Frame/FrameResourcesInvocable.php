<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavLink;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The Frame-resources contributor — the registered popcorn capability that yields a section's
 * Frame `AdminResource` children, behind the existing `NavExpander` / `InvocableNavItem` seam: a
 * section that needs its resource children declares an {@see InvocableNavItem} whose `input`
 * carries the section key and realm; resolution dispatches this capability to build the child
 * `NavLink`s.
 *
 * Given a section key (+ the user / realm / tenant read off the current
 * {@see NavContext}, bound into the container by `NavRegistry` during the build)
 * it: selects the resources whose `nav->section` matches; filters by realm
 * (through {@see ParticleResourceRegistry::realmsFor()}, the one membership authority) and
 * per-resource `viewAny` RBAC (service-backed resources with no model stay visible); sorts by
 * `navOrder` (nulls last) then label; and emits each as a child `NavLink`. Hand-authored
 * non-resource children passed as `input['static']` are merged into that SAME ordering and may
 * carry their own `navOrder`.
 *
 * ## Why this is a package and used not to be
 *
 * This collector was host code at exactly one host — the flagship's `App\Navigation\
 * FrameResourcesInvocable` — so every other beam host that declared a `section` on a resource got
 * nothing from it. Nothing in the mechanism is host-specific: the SECTION SKELETON and the
 * entitlement/permission vocabulary are host IA and stay at the host (ADR-0092), while *"a
 * section's resources attach in `navOrder`, realm-filtered and `viewAny`-gated, with their href
 * joined off `routeName`"* is the same sentence at every host. Same split as the router half
 * beside it: **the list is host code, the projection is package code**
 * ({@see RouteContextProjector}).
 *
 * ## One sort, because ordering must not consult what BACKS an item
 *
 * Until 2026-08-27 this built two independently-ordered lists and concatenated them —
 * resources sorted by `navOrder`, statics appended after. That made `navOrder` a
 * resource-only key: **no value of it could lift a static above a resource**, so the flagship's
 * operator Platform section calling Dashboard "Head of the Platform section" was unachievable,
 * and Dashboard rendered fifth.
 *
 * The rejected alternative was inverting the priority (statics first, "they're more
 * custom"). That trades one unexpressible order for another, and it is backwards for
 * this estate's actual statics — Connectors and Operations both WANT to trail. So the
 * rule is plain numeric ordering over one merged list, and the item's kind is not an
 * input to it. The JS half already ruled this way: `beam-ux/src/nav/RealmNav.tsx`
 * — *"ordering IS the authored `nav_order` … never re-sorted"*.
 *
 * `usort` is stable in PHP 8, and that is load-bearing here rather than incidental:
 * equal `navOrder` preserves each group's internal order, so resources keep their
 * label tiebreak and statics keep their AUTHORED array order. Anything declaring no
 * `navOrder` sorts to `PHP_INT_MAX` and trails exactly as before — which is why this
 * change moves no existing item and needs no value assigned to migrate.
 */
class FrameResourcesInvocable implements Invocable
{
    /** The registered capability name a section's `InvocableNavItem` points at. */
    public const NAME = 'app-nav.frame-resources';

    public function __construct(
        private ResourceRegistry $registry,
        private RealmRegistry $realms,
        private ParticleResourceRegistry $particles,
        private RouteContextProjector $routes,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        $section = (string) ($input['section'] ?? '');
        $realm = (string) ($input['realm'] ?? '');

        $context = $this->context();
        $user = $context?->user;

        // The ONE derivation of "where does this routeName live" — see resourceChildren(). Built once
        // per section build rather than per child; `routeContext()` walks the whole resource catalog.
        //
        // An unknown realm is a fact about the HOST, not a grammar error, and the estate rule is that
        // such a check must not throw — `RouteContextProjector::hrefs()` opens with `resolve()`, which
        // does. So the realm is resolved here first and an unresolvable one yields no join at all: the
        // section still renders, on the key-derived fallback below. Same reasoning, same shape, as
        // {@see FrameNavContribution}'s `tryResolve` decline.
        $hrefs = $realm === '' || $this->realms->tryResolve($realm) === null
            ? []
            : $this->routes->hrefs($realm);

        $children = [
            ...$this->resourceChildren($section, $realm, $user, $hrefs),
            ...$this->staticChildren(is_array($input['static'] ?? null) ? $input['static'] : [], $hrefs),
        ];

        // ONE sort over both kinds — see the class docblock. Ordering keys on `navOrder` alone and
        // never on whether the child came from a resource or a static, which is the whole point.
        // Stable, so equal orders keep each group's internal sequence (resource label tiebreak,
        // static authored order) and an undeclared `navOrder` still trails.
        usort($children, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return ['items' => array_map(fn (array $child): array => $child['link']->toArray(), $children)];
    }

    /**
     * The resources that auto-attach into a section, ordered by `navOrder` (nulls
     * last) then label — each a child `NavLink` carrying the resource's icon +
     * routeName, **href derived from the leaf that `routeName` names**. A model-backed
     * resource is additionally `viewAny`-gated (secure-by-omission).
     *
     * The label tiebreak stays here rather than moving to the merged sort: it orders resources
     * against each other, and the merged sort is deliberately kind-blind. Stability carries it
     * through.
     *
     * ## The href was a THIRD, independent derivation, and it disagreed with the other two
     *
     * Until 2026-08-29 this built `href: $base.'/'.$def->key` off the RESOURCE KEY — consulting
     * neither the route stem nor the leaf path, and so agreeing with the router only where the key,
     * the declared `routeName` stem and the host's chosen path happened to be the same word. Six of
     * the flagship tenant nav's 14 routeName-carrying seats pointed at a path no leaf serves:
     *
     * | seat           | old href          | leaf                       |
     * |----------------|-------------------|----------------------------|
     * | Studio         | `/compositions`   | `/studio`                  |
     * | Context scopes | `/context-scopes` | `/knowledge/scopes`        |
     * | Concepts       | `/concepts`       | `/knowledge/graph/concepts`|
     * | Rules          | `/rules`          | `/knowledge/graph/rules`   |
     * | Agents         | `/agents`         | `/threads/agents`          |
     * | Review queue   | `/review-queue`   | `/review`                  |
     *
     * Latent rather than live there — that host's tenant rail renders section-level hand-written
     * hrefs — which is exactly why nobody noticed, and exactly what a whole-realm generator would
     * have shipped as six 404s. The `match` glob was wrong with it, so those seats could never
     * active-stamp either.
     *
     * `routeName` is the join, and it is the RIGHT join: {@see \Schemastud\Frame\Registry\RouteContextEntry}
     * documents it as *"stable identity; the RouteRegistry + nav join key"*, and
     * {@see RouteContextValidator} already asserts every nav `routeName` is bound to a leaf.
     *
     * ## An unjoinable seat keeps its old href rather than vanishing
     *
     * A seat whose `routeName` names no leaf falls back to the key derivation this method used to
     * do unconditionally. That is deliberate: a silently wrong href is bad, but a nav seat that
     * disappears is a regression a reader cannot distinguish from a permissions denial, and the
     * secure-by-omission gating above means "absent" already MEANS something else here. The
     * condition is not silent either — {@see RouteContextValidator} throws
     * `Nav node routeName [x] is unbound` at manifest emit before this could ship, and a host's own
     * doctor audit can report it advisorily for the paths that reach the navigation without going
     * through the manifest contributor. It is also the arm that carries a host which has spelled
     * out no {@see RouteContextPlan} at all: the empty plan still emits one leaf per realm
     * resource, and a seat outside that set keeps a working URL instead of an empty one.
     *
     * @param  array<string, string>  $hrefs  routeName => leaf href, from {@see RouteContextProjector::hrefs()}
     * @return array<int, array{order: int, link: NavLink}>
     */
    private function resourceChildren(string $section, string $realm, ?Authenticatable $user, array $hrefs): array
    {
        $matches = array_filter(
            $this->registry->all(),
            fn (ResourceDefinition $def): bool => $def->nav->section === $section
                && $this->resourceInRealm($def->key, $realm)
                && $this->resourceViewable($def, $user),
        );

        usort($matches, function (ResourceDefinition $a, ResourceDefinition $b): int {
            $ao = $a->nav->navOrder ?? PHP_INT_MAX;
            $bo = $b->nav->navOrder ?? PHP_INT_MAX;

            return $ao <=> $bo ?: strcmp($a->nav->label, $b->nav->label);
        });

        // The FALLBACK base only — the key derivation this method used to do unconditionally, kept for
        // the unjoinable seat the docblock argues about. A realm mounts under its `routeBase`, so a
        // bare `/{key}` under a central realm would point at the root realm; normalised so a
        // root-based realm (`/`) yields no prefix and the operator realm yields `/operator/…`.
        // Note it still cannot see a shell, which is precisely why the fallback is a fallback.
        $definition = $this->realms->tryResolve($realm);
        $base = $definition === null ? '' : rtrim($definition->routeBase, '/');

        return array_map(
            function (ResourceDefinition $def) use ($base, $hrefs): array {
                $routeName = $def->nav->routeName ?? $def->key.'.index';
                $href = $hrefs[$routeName] ?? $base.'/'.$def->key;

                return [
                    'order' => $def->nav->navOrder ?? PHP_INT_MAX,
                    'link' => NavLink::make(
                        title: $def->nav->label,
                        href: $href,
                        match: trim($href, '/').'*',
                        icon: $def->nav->icon,
                        routeName: $routeName,
                    ),
                ];
            },
            array_values($matches),
        );
    }

    /**
     * Hand-authored non-resource children — surfaces that aren't Frame AdminResources but belong in
     * the section (e.g. an admin Connectors catalogue, or any of the bespoke composite pages a
     * host's {@see RouteContextPlan} declares as standalone rather than as a frame resource).
     *
     * These are MERGED into the resource ordering rather than appended after it — see the class
     * docblock. An optional `navOrder` places one anywhere in the section, including ahead of every
     * resource; omitting it trails exactly as the old append did, which is why no existing caller
     * needed a value when the two lists were merged.
     *
     * ## These take the leaf href too, when they name a leaf
     *
     * A static child is hand-authored in the host's own section skeleton, where it writes `href` and
     * `routeName` beside each other — and the leaf that `routeName` names writes the same URL a
     * second time in the host's {@see RouteContextPlan}. Deriving it means the two cannot disagree
     * later, on the same reasoning as the resource children above.
     *
     * The declared `href` survives as the fallback for the case that makes it non-redundant: a
     * static with NO `routeName` (nothing to join on), and a static naming a leaf the realm does not
     * emit. It is not dead weight — nothing forces a static to be manifest-backed, and a host that
     * has bound no plan emits no standalone leaves at all.
     *
     * @param  array<int, array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}>  $static
     * @param  array<string, string>  $hrefs  routeName => leaf href, from {@see RouteContextProjector::hrefs()}
     * @return array<int, array{order: int, link: NavLink}>
     */
    private function staticChildren(array $static, array $hrefs): array
    {
        return array_map(
            function (array $child) use ($hrefs): array {
                $routeName = $child['routeName'] ?? null;
                $href = ($routeName !== null ? $hrefs[$routeName] ?? null : null) ?? $child['href'];

                return [
                    'order' => $child['navOrder'] ?? PHP_INT_MAX,
                    'link' => NavLink::make(
                        title: $child['title'],
                        href: $href,
                        match: trim($href, '/').'*',
                        icon: $child['icon'] ?? null,
                        routeName: $routeName,
                    ),
                ];
            },
            $static,
        );
    }

    /**
     * Whether a resource may appear for this user — the per-resource `viewAny`
     * gate. A service-backed union resource (no model) has no policy model to
     * check, so it stays visible; the API enforces its own access. With no
     * authenticated user, filtering is skipped (endpoints still enforce).
     *
     * ⚠️ ASKS the Gate for whatever policy is bound and skips when the model has none, or none
     * declaring `viewAny` — the same reading `ResourceFiltersController::gateOnModel()` makes, for the
     * same reason: Laravel DENIES an ability nobody defined, so the unconditional `can()` this used to
     * be hid the seat of every resource that leans on its row-level scope instead of a class policy
     * (ADR-0156 §83: for a filterable resource the data-filters query IS the index's gate). Measured
     * 2026-09-02 at the flagship (api-surface-coherence 135): `circuit-runs`, `beam-ux-entry`,
     * `rules`, `evidence` and `hooks` all vanished for every non-Root member while their indexes
     * answered by URL — a wrong absence a reader cannot tell from a permissions denial. A resource
     * that DOES bind a policy is still permission-gated.
     *
     * Read posture only. A missing policy on a WRITE stays denied — one posture per kind, decided at
     * the declaration, not two per surface.
     */
    private function resourceViewable(ResourceDefinition $def, ?Authenticatable $user): bool
    {
        if ($user === null || $def->model === null) {
            return true;
        }

        $policy = Gate::getPolicyFor($def->model);

        if ($policy === null || ! method_exists($policy, 'viewAny')) {
            return true;
        }

        return $user->can('viewAny', $def->model);
    }

    /**
     * ⚠️ This read USED to go straight to `config('frame.realms')`, bypassing both registries — a THIRD
     * route to membership beside the manifest's ladder climb and the Frame gate's own copy. It agreed with
     * them only because all three bottomed out in the same config key and the ladder's `explicit` rung has
     * never been used. A resource that named its realms at registration would have been manifest-visible
     * and NAV-INVISIBLE: no error, no empty state, just a section quietly missing a child.
     *
     * Asks per key rather than fetching the realm's whole key set, because the caller is inside an
     * `array_filter` over the resource catalog — the per-key read is the cheaper shape here.
     */
    private function resourceInRealm(string $key, string $realm): bool
    {
        return in_array($realm, $this->particles->realmsFor($key), true);
    }

    /**
     * The current build context, bound into the container by `NavRegistry::build`
     * for the duration of the build. Null when resolved outside a build (the
     * capability then yields ungated resources — the endpoints still enforce).
     */
    private function context(): ?NavContext
    {
        $container = Container::getInstance();

        return $container->bound(NavContext::class)
            ? $container->make(NavContext::class)
            : null;
    }
}
