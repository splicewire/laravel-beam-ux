<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Http\Request;
use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavNode;
use Rushing\DataNav\NavRegistry;
use Rushing\DataNav\NavTree;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Splicewire\Beam\Dashboard\SectionOverview;
use Splicewire\Beam\Localization\ShellText;
use Splicewire\Beam\Nav\NavGroupMatch;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Fills frame's {@see FrameNavContributor} plug — so `/frame/manifest` carries `nav` and
 * `routeContext` at any host that installs beam-ux, instead of only at the one host that
 * hand-wrote its own manifest controller.
 *
 * ## The realm is the only thing this needs from the host, and it is a route fact
 *
 * A host mounting the manifest once per realm stamps `->defaults('realm', …)` and gets a
 * realm-scoped answer; a host mounting frame's single default route stamps nothing, and the realm
 * falls back to {@see DefaultDashboardRealm}: the configured realm, or the personal user surface for
 * an authenticated caller with no current team when that default is tenant. Neither is discovery — the realm's
 * resource MEMBERSHIP is still `config('frame.realms')`, a list the host spells out
 * (api-surface-coherence 142).
 *
 * ## Three ways this declines, and why none of them throws
 *
 * *"Is there a realm called this here?"*, *"is a navigation registered for it?"* and *"has anything
 * been placed in it?"* are all facts about the HOST, which AGENTS.md §*A check whose answer depends
 * on the host must not throw* makes absences rather than fatals. So:
 *
 *  - an unknown realm ⇒ `null`, and the payload keeps exactly the keys it had;
 *  - a realm with no registered navigation ⇒ an EMPTY nav tree beside a real `routeContext`. This
 *    is the common case on a fresh host and it is the useful half: the router table derives from
 *    the host's realm membership and needs no navigation to exist. Declining outright here would
 *    throw away the half that works, which is precisely how a seam ships and delivers nothing.
 *
 * What DOES throw is {@see RouteContextValidator}: a duplicate route name, an unbound nav seat or a
 * non-flat mount are grammar errors in the host's own plan, gettable right without knowing the host.
 */
class FrameNavContribution implements FrameNavContributor
{
    public function __construct(
        protected RouteContextProjector $routes,
        protected RouteContextValidator $validator,
        protected NavRegistry $navigations,
        protected RealmRegistry $realms,
        protected Request $request,
    ) {}

    public function contributeNav(?string $realm): ?array
    {
        $realm = app(DefaultDashboardRealm::class)->resolve($realm, $this->request);

        if (! is_string($realm) || $realm === '') {
            return null;
        }

        // An unknown realm is a host fact, not a grammar error — `tryResolve` rather than `resolve`.
        if ($this->realms->tryResolve($realm) === null) {
            return null;
        }

        $context = $this->context($realm);
        $routeContext = $this->routes->routeContext($realm, $context);
        $nav = $this->navigation($realm, $context);

        if ($this->navigations->tryResolve($realm) instanceof DeclaredSectionNavigation) {
            $nav = $this->pruneUnbound($routeContext, $nav, $realm);
        }

        $this->validator->assert($routeContext, $nav);

        return [
            'nav' => [...$nav->toArray(), 'realm' => $realm],
            'routeContext' => $routeContext,
        ];
    }

    /**
     * The resolved, gated, active-stamped navigation for one realm — or an empty tree where this
     * host has registered no navigation under that key. {@see NavRegistry::build()} throws
     * {@see RegistryMiss} on an unknown key, and "this host registered no navigation" is exactly
     * the host-dependent answer that must not be fatal.
     */
    protected function navigation(string $realm, ?NavContext $context = null): NavTree
    {
        try {
            $tree = $this->navigations->build($realm, $context ?? $this->context($realm));
        } catch (RegistryMiss) {
            return NavTree::make([]);
        }

        // A package-projected navigation's text is already resolved ({@see NavSectionProjector}).
        if ($this->navigations->tryResolve($realm) instanceof DeclaredSectionNavigation) {
            return $tree;
        }

        // A code-authored navigation (a host's own NavRegistry tree, or a package's) resolves its page titles here, at
        // emit, under the namespace of whoever registered it (REVIEW-B7 U1; ruling 01 §3: pages by their authoring code,
        // so a host's are host keys).
        $registry = $this->navigations->unfiltered();
        $registrant = $registry instanceof RecordsRegistrants ? $registry->registrantOf($realm) : null;

        return NavTree::make($this->localizedNodes($tree->items, $registrant, app(ShellText::class)));
    }

    /**
     * Each authored node's title, keyed by its route name (`nav.routes.<a/b>.title` in the registrant's namespace); a node
     * with no route name is still presented, so it reads as the author's own literal in any language.
     *
     * The author's text reaches below an invocable item too, through the SAME call (frame-foundation 82). The item's
     * `input.static` rows and `input.groups` labels are words the author wrote, just like its title. Each is resolved here
     * under the same registrant: a row keyed by its route name, a group by its tab's realm-free route name, exactly as
     * {@see NavSectionProjector::localizedRows()} and `localizedGroups()` key a package's. The expanded children those
     * words became (a static row's link, a group tab, at any depth) then wear the resolved word. Every other expanded
     * child (a resource leaf, a persisted entry) carries its own text and is never re-keyed or re-presented here.
     *
     * @param  array<int, NavNode>  $nodes
     * @return array<int, NavNode>
     */
    private function localizedNodes(array $nodes, ?string $registrant, ShellText $shell): array
    {
        return array_map(function (NavNode $node) use ($registrant, $shell): NavNode {
            $copy = clone $node;
            if ($node->title !== '') {
                $copy->title = $shell->navRouteTitle($node->routeName, $registrant, $node->title);
            }
            if ($node instanceof InvocableNavItem) {
                $authored = $this->localizedInput($copy, $registrant, $shell);
                $copy->children = self::retitled($node->children, $authored);
            } else {
                $copy->children = $this->localizedNodes($node->children, $registrant, $shell);
            }

            return $copy;
        }, $nodes);
    }

    /**
     * Resolve an invocable item's authored `input.static` titles and `input.groups` labels in place, and return what each
     * expanded child minted from them should now read: by route name (`routes`) and, for a row with none, by its literal
     * (`literals`). A group tab's route name is the one {@see FrameResourcesInvocable::foldGroups()} mints, against the
     * realm the item names.
     *
     * @return array{routes: array<string, string>, literals: array<string, string>}
     */
    private function localizedInput(InvocableNavItem $item, ?string $registrant, ShellText $shell): array
    {
        $input = $item->input;
        $authored = ['routes' => [], 'literals' => []];

        if (is_array($input['static'] ?? null)) {
            foreach ($input['static'] as $i => $row) {
                $title = is_array($row) ? ($row['title'] ?? null) : null;
                if (! is_string($title) || $title === '') {
                    continue;
                }
                $routeName = is_string($row['routeName'] ?? null) && $row['routeName'] !== '' ? $row['routeName'] : null;
                $resolved = $shell->navRouteTitle($routeName, $registrant, $title);
                $input['static'][$i]['title'] = $resolved;
                $routeName === null ? $authored['literals'][$title] = $resolved : $authored['routes'][$routeName] = $resolved;
            }
        }

        if (is_array($input['groups'] ?? null)) {
            $routeBase = $this->realms->tryResolve((string) ($input['realm'] ?? ''))?->routeBase ?? '/';
            foreach ($input['groups'] as $i => $group) {
                if (! is_array($group) || ! is_string($group['label'] ?? null) || ! is_string($group['match'] ?? null)
                    || ! NavGroupMatch::isWellFormed($group['match'])) {
                    continue;
                }
                $resolved = $shell->navRouteTitle(NavGroupMatch::resolve($group['match'], '/')->routeName(), $registrant, $group['label']);
                $input['groups'][$i]['label'] = $resolved;
                $authored['routes'][NavGroupMatch::resolve($group['match'], $routeBase)->routeName()] = $resolved;
            }
        }

        $item->input = $input;

        return $authored;
    }

    /**
     * Expanded children, at any depth, with each one an authored word became wearing its resolved form; the rest untouched.
     *
     * @param  array<int, NavNode>  $nodes
     * @param  array{routes: array<string, string>, literals: array<string, string>}  $authored
     * @return array<int, NavNode>
     */
    private static function retitled(array $nodes, array $authored): array
    {
        if ($authored['routes'] === [] && $authored['literals'] === []) {
            return $nodes;
        }

        return array_map(static function (NavNode $node) use ($authored): NavNode {
            $copy = clone $node;
            $copy->title = $node->routeName !== null
                ? $authored['routes'][$node->routeName] ?? $node->title
                : $authored['literals'][$node->title] ?? $node->title;
            $copy->children = self::retitled($node->children, $authored);

            return $copy;
        }, $nodes);
    }

    /**
     * Drop the nodes naming a route this host does not mount — but ONLY from a navigation a package
     * produced, never from one the host spelled out.
     *
     * ## Why the two are treated differently
     *
     * {@see RouteContextValidator::assert()} throws on a nav `routeName` with no RouteContext entry,
     * and {@see FrameResourcesInvocable} leans on that throw by design: its docblock argues an
     * unjoinable seat should keep a fallback href rather than vanish, because the validator will
     * catch it "before this could ship". That reasoning is sound for HOST-authored nav — the throw
     * reaches the person who wrote the offending line, at their own host, while they are building it.
     *
     * It does not survive package contribution. The identical throw becomes a 500 on `/frame/manifest`
     * caused by a package the host merely INSTALLED, over a route the host was never obliged to
     * mount. "Does this host mount that route" is a fact about the host, and the estate rule is that
     * such a check reports an absence rather than a fatal (`docs/agents/traps/audits-and-findings.md`
     * — the same shape as the event catalog whose boot-time throw made `~/Herd/tower` unbootable).
     *
     * So the validator is NOT weakened: a host that mis-spells its own routeName still gets the
     * throw, immediately, exactly as before. Only a contributed node degrades to absent.
     *
     * A `*.section` node is left alone because the validator already exempts it — a section header
     * binds to no leaf, so it is never the unbound thing.
     *
     * A seat with an overview (ADR-0222 decision 9) is bound to its overview's leaf instead, so it is judged like any bound
     * node, and then dropped like an empty section when gating has left it no child. This runs after the host's stages
     * have gated every child, so it is the rail's own answer to "is there anything here for this reader", and nothing else
     * asks it on the overview's behalf.
     */
    protected function pruneUnbound(array $routeContext, NavTree $nav, ?string $realm = null): NavTree
    {
        $bound = [];

        foreach ($routeContext as $entry) {
            $bound[$entry->routeName] = true;
        }

        $groupTabs = $realm === null ? [] : $this->groupTabs($realm);

        return NavTree::make($this->keepBound($nav->items, $bound, $this->overviewRoutes(), $groupTabs));
    }

    /**
     * Every nav GROUP tab the fold can draw in `$realm` ({@see FrameResourcesInvocable::foldGroups()}, analytics-area 22),
     * keyed by the route name of the SEAT it sits directly under: the seat's `{key}.section`, or its overview's list route
     * when the seat lands on one ({@see NavSectionProjector}). Each tab is named by the group's declared identity, its
     * {@see NavGroupMatch::routeName()} against the realm's route base, exactly as the fold mints it. The build's round
     * trip strips server-only meta, so the tab is recognised by what was DECLARED, never inferred from the tree's shape
     * (review-r1 98e0e49c, build-qa: a Developer wrapper puts ordinary seats one level down).
     *
     * @return array<string, array<string, true>>  seat route name => group tab route names
     */
    private function groupTabs(string $realm): array
    {
        if (! app()->bound(NavSectionRegistry::class)) {
            return [];
        }
        $routeBase = $this->realms->tryResolve($realm)?->routeBase ?? '/';

        $tabs = [];
        foreach (app(NavSectionRegistry::class)->for($realm) as $section) {
            foreach ($section->groups as $group) {
                if (! NavGroupMatch::isWellFormed($group['match'])) {
                    continue;
                }
                $name = NavGroupMatch::resolve($group['match'], $routeBase)->routeName();
                $tabs[$section->key.'.section'][$name] = true;
                $tabs[SectionOverview::routeNameFor($realm, $section->key)][$name] = true;
            }
        }

        return $tabs;
    }

    /**
     * @param  array<int, NavNode>  $nodes
     * @param  array<string, true>  $bound
     * @param  array<string, true>  $overviews  the list route of every flagged seat's overview, which an empty seat is bound to
     * @param  array<string, array<string, true>>  $groupTabs  {@see groupTabs()}
     * @param  array<string, true>  $tabsHere  the group tabs the fold can have drawn at THIS level: the parent seat's, else none
     * @return array<int, NavNode>
     */
    private function keepBound(array $nodes, array $bound, array $overviews, array $groupTabs = [], array $tabsHere = []): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            $name = $node->routeName;

            if ($name !== null && ! str_ends_with($name, '.section') && ! isset($bound[$name])) {
                continue;
            }

            $children = $this->keepBound($node->children, $bound, $overviews, $groupTabs, $groupTabs[(string) $name] ?? []);

            // A nav GROUP tab lands on its first SURVIVING member (analytics-area 22; review-r1 b1ddaadb). The fold minted
            // its href from its first member BEFORE the host's gate stages ran, and they (and the unbound prune above)
            // remove children without retargeting the parent: a reader denied that member would click into it. This
            // runs after both, so the tab now lands where this reader can go. A group left with no member is dropped by
            // the empty-`.section` rule below. Only a tab the fold drew: a declared group's name, directly under its seat.
            if ($children !== [] && isset($tabsHere[(string) $name])) {
                $node = clone $node;
                $node->href = $children[0]->href;
            }

            // An EMPTY contributed seat is dropped. A package cannot know which realm its section
            // belongs to at a given host — realm membership is the host's `config/frame.realms` list,
            // and the same beam-ux `ops` resources sit in `operator` at the flagship and in `tenant`
            // at the starter. So a package declares the seats it plausibly owns and the ones with
            // nothing under them here never render, instead of leaving a dead header a user can click
            // into an empty page. This is why declaring for two realms is not sloppy: it is the only
            // honest way to say "wherever these resources ended up".
            //
            // Only a `*.section` seat, and only in a contributed tree — a HOST's empty section is
            // deliberate (the flagship's Calendar seat is a curated standalone page that attaches no
            // resources by design), and this method never runs over a host's navigation at all.
            if ($children === [] && $name !== null && (str_ends_with($name, '.section') || isset($overviews[$name]))) {
                continue;
            }

            $kept[] = $node->stamped(
                active: $node->active,
                activeTrail: $node->activeTrail,
                children: $children,
            );
        }

        return $kept;
    }

    /**
     * The overview route of every flagged seat, in every realm. Route names carry the realm, so one set serves any tree.
     *
     * @return array<string, true>
     */
    private function overviewRoutes(): array
    {
        if (! app()->bound(NavSectionRegistry::class)) {
            return [];
        }

        $sections = app(NavSectionRegistry::class);
        $routes = [];

        foreach (array_keys($this->realms->all()) as $realm) {
            foreach (SectionOverview::flagged($realm, $sections) as $section) {
                $routes[SectionOverview::routeNameFor($realm, $section->key)] = true;
            }
        }

        return $routes;
    }

    /**
     * The host-vocabulary-free build context: the authenticated user, the request, and the realm in
     * the opaque attributes bag. A host whose gate stages need more (a tenant, an entitlement
     * subject) binds its own {@see FrameNavContributor} over this one — the port is the seam for
     * exactly that, and beam-ux naming a tier above itself to pre-empt it would be the defect this
     * promotion exists to remove, one tier up.
     */
    protected function context(string $realm): NavContext
    {
        return new NavContext(
            user: $this->request->user(),
            request: $this->request,
            attributes: ['realm' => $realm],
        );
    }
}
