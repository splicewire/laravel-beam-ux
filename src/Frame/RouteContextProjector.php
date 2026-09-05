<?php

namespace Splicewire\Beam\Ux\Frame;

use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Registry\RouteContextEntry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Builds the router half of the frame manifest: the flat {@see RouteContextEntry}[] a JS host
 * expands into router leaves, plus the `routeName => href` join a nav seat resolves its URL through.
 *
 * ## Why this is a package and used not to be
 *
 * This engine was host code at exactly one host, and that is the whole finding behind the
 * promotion: `nav` and `routeContext` reached the wire at ONE root in the estate, because that
 * root had written its own copy of frame's manifest controller. Every host cut from the starter
 * inherited `resources` + `contexts` and nothing else.
 *
 * Nothing about the derivation was host-specific — the derivation is *"a realm's resources become
 * a list leaf, plus an edit/widget/detail twin where the declaration allows one, plus the
 * resource-less pages the host names."* What was host-specific is the seven LISTS it reads, and
 * those stay host-owned in {@see RouteContextPlan} (api-surface-coherence 141/142: the host still
 * spells out its realms and mounts). **The list is host code; the projection is package code.**
 *
 * ## HARD GUARDRAIL — flat only
 *
 * Every entry carries per-route properties and nothing that encodes parent/child nesting.
 * Record-nested sub-routes (`circuits/:id/runs`, silo tabs) and pre-auth/public routes stay
 * HAND-WRITTEN in the host router; this projector never emits them.
 * {@see RouteContextValidator} enforces it at emit.
 *
 * ## What it deliberately does NOT do
 *
 * It does not discover, contribute or auto-mount anything. A resource reaches a realm only by the
 * host's own realm membership list (`config('frame.realms')`, read through
 * {@see ParticleResourceRegistry::keysForRealm()}), and a resource-less page exists only because a
 * host wrote it into its plan. Auto-mount retired as vocabulary (141) and this does not revive it.
 */
class RouteContextProjector
{
    public function __construct(
        protected ResourceRegistry $registry,
        protected ParticleResourceRegistry $particles,
        protected RealmRegistry $realms,
        protected RouteContextPlan $plan,
    ) {}

    /**
     * The flat RouteContext for one realm: a list leaf for every resource the host's membership
     * list places in that realm, its single-record twin where the declaration allows one, plus the
     * realm's resource-less standalone pages. Leaves inherit the realm's guard unless a standalone
     * entry pins its own.
     *
     * @return array<int, RouteContextEntry>
     */
    public function routeContext(string $realm): array
    {
        $definition = $this->realms->resolve($realm);
        $guard = $definition->guard;
        $keys = $this->particles->keysForRealm($realm);
        $entries = [];

        foreach ($this->registry->all() as $resource) {
            if (! in_array($resource->key, $keys, true)) {
                continue;
            }

            array_push($entries, ...$this->resourceLeaves($resource, $guard));
        }

        foreach ($this->plan->standaloneFor($definition) as $page) {
            $entries[] = new RouteContextEntry(
                routeName: $page['routeName'],
                path: $page['path'],
                shell: $page['shell'] ?? 'app',
                // A standalone page inherits the realm's default guard unless it pins its own.
                guard: $page['guard'] ?? $guard,
                mounts: $page['mounts'],
            );
        }

        return $entries;
    }

    /**
     * The SPA URL each of a realm's leaves actually lives at, keyed by `routeName` — so a nav
     * seat's `href` and the route it points at come from ONE derivation instead of two.
     *
     * ## The shell contributes a segment only in a NON-CENTRAL realm
     *
     * A central realm's mount component registers no shell registry, so its leaves mount FLAT at
     * the realm base whatever they declare; a scoped realm's mount filters the routeContext by
     * shell and nests each leaf under {@see RouteContextPlan::$shellBases}. Deriving that from
     * {@see \Schemastud\Frame\Realm\RealmDefinition::$central} keeps ONE rule instead of a branch
     * per realm — but it is a statement about how two mount components are wired, so a realm that
     * starts (or stops) passing a shell registry must be re-measured here.
     *
     * @return array<string, string> routeName => absolute SPA path
     */
    public function hrefs(string $realm): array
    {
        $definition = $this->realms->resolve($realm);
        $base = trim($definition->routeBase, '/');

        $hrefs = [];

        foreach ($this->routeContext($realm) as $entry) {
            $shell = $definition->central ? '' : ($this->plan->shellBases[$entry->shell] ?? '');

            $segments = array_filter(
                [$base, $shell, trim($entry->path, '/')],
                fn (string $segment): bool => $segment !== ''
            );

            $hrefs[$entry->routeName] = '/'.implode('/', $segments);
        }

        return $hrefs;
    }

    /**
     * The shell keys this projector can emit, and the path segment each mounts at — exposed so a
     * test can pin the emitted shell set against the map {@see hrefs()} derives from, rather than a
     * new shell silently dropping its segment from every href built off it.
     *
     * @return array<string, string>
     */
    public function shells(): array
    {
        return $this->plan->shellBases;
    }

    /**
     * The flat leaves for one resource: its list route, plus at most one single-record twin — a
     * heavyweight `widget` mount, a simple `edit` form, or a read-only `detail`.
     *
     * The list `routeName` is the resource's DECLARED one (the nav join key); the twin suffixes
     * `.edit` to stay unique. That suffix is POSITIONAL, not a verb — it names "the per-record twin
     * of the list leaf" and is deliberately not derived from `mounts`. `routeName` is documented on
     * {@see RouteContextEntry} as *"stable identity; the RouteRegistry + nav join key"*; deriving it
     * from a capability flag would silently RENAME a route and orphan its binding every time a
     * resource gained or lost `editable`. What renders is `mounts`, which is the slot that varies.
     *
     * The twin is gated on `editable` / `showable`, never on `creatable`: `editable` is documented
     * as governing in-place edit independently of `creatable`, and the generic handler already 405s
     * `update` off it — so gating on `creatable` hands a resource an edit shell whose save the
     * server refuses.
     *
     * @return array<int, RouteContextEntry>
     */
    protected function resourceLeaves(ResourceDefinition $definition, ?string $guard): array
    {
        $key = $definition->key;

        // A folded resource contributes no route leaf — its surface is another resource's page.
        if (in_array($key, $this->plan->foldedResources, true)) {
            return [];
        }

        $listRoute = $definition->nav->routeName ?? $key.'.index';

        // The in-shell route STEM — the path segment and the `<stem>.edit` twin — follows the
        // DECLARED list route, not the resource key. For most resources they are the same word and
        // the fallback keeps it that way; where a registry key normalises differently from the SPA
        // surface it has always lived at, the key and the path are genuinely two facts and
        // deriving the path from the key silently moves the page.
        $stem = str_ends_with($listRoute, '.index')
            ? substr($listRoute, 0, -strlen('.index'))
            : $key;

        // A shelled resource nests its LIST leaf under a hand-written layout at a real path, and
        // does NOT auto-emit an edit leaf — its record routes stay hand-written.
        if (isset($this->plan->shelledResources[$key])) {
            $shelled = $this->plan->shelledResources[$key];

            return [new RouteContextEntry(
                routeName: $listRoute,
                path: $shelled['path'],
                shell: $shelled['shell'],
                guard: $guard,
                mounts: 'list',
                resource: $key,
            )];
        }

        // The path may be pinned by host IA while the routeName stays the stem's — `routeName` is
        // documented as stable identity, so only the URL half moves.
        $path = $this->plan->resourcePaths[$key] ?? $stem;

        $entries = [new RouteContextEntry(
            routeName: $listRoute,
            path: $path,
            shell: 'app',
            guard: $guard,
            mounts: 'list',
            resource: $key,
        )];

        $widget = $this->plan->heavyweightEditors[$key] ?? null;

        if ($widget !== null) {
            $entries[] = new RouteContextEntry(
                routeName: $stem.'.edit',
                path: $path.'/:id',
                shell: 'app',
                lazy: true,
                guard: $guard,
                mounts: 'widget',
                widget: $widget,
                resource: $key,
            );
        } elseif ($definition->editable) {
            $entries[] = new RouteContextEntry(
                routeName: $stem.'.edit',
                path: $path.'/:id',
                shell: 'app',
                guard: $guard,
                mounts: 'edit',
                resource: $key,
            );
        } elseif ($definition->showable && ! in_array($key, $this->plan->detaillessResources, true)) {
            // `showable` is the server saying `records/{id}` answers even for a read-only INSPECT
            // resource. Without this arm the server answered the record endpoint while the SPA had
            // no route to ask from.
            //
            // ⚠️ Suppressions live in {@see RouteContextPlan::$detaillessResources}, and adding one
            // is load-bearing rather than cosmetic. Whether an UNBOUND leaf renders generically is
            // realm-asymmetric: a mount component wired with a `mounts` fallback degrades to a
            // read-only shell, while one asserting its route context with the default `unbound:
            // 'throw'` takes the WHOLE realm down at boot — every page, not just this one.
            $entries[] = new RouteContextEntry(
                routeName: $stem.'.edit',
                path: $path.'/:id',
                shell: 'app',
                guard: $guard,
                mounts: 'detail',
                resource: $key,
            );
        }

        return $entries;
    }
}
