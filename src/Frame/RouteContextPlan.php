<?php

namespace Splicewire\Beam\Ux\Frame;

use Schemastud\Frame\Realm\RealmDefinition;

/**
 * The HOST-OWNED half of the frame router projection: seven lists naming this host's own
 * information architecture. Nothing here is derived, discovered or contributed by a package —
 * a host spells every entry out, which is `api-surface-coherence` **141** ("SPELL IT OUT") and
 * **142** ("realm → host-side list") applied to the router.
 *
 * The other half — {@see RouteContextProjector} — is package code, and is what stops every host
 * re-deriving leaf paths, shell nesting, edit/detail twins and href joins from these lists by
 * hand. The split is the whole point of the promotion: **the list is host code, the projection is
 * package code.**
 *
 * A host supplies its plan either by publishing `config('beam.ux.frame_nav.route_context')` and
 * letting {@see fromConfig()} read it, or — where the lists want real docblocks explaining *why*
 * a resource sits where it does — by binding a constructed instance in a provider. Both are the
 * host spelling it out; only the second survives code review with its reasoning attached, which
 * is why the flagship uses it.
 */
class RouteContextPlan
{
    /**
     * @param  array<string, string>  $heavyweightEditors  resource key → registered widget name. Its single-editor route mounts a heavyweight `widget` (and is emitted `lazy`) instead of a simple `edit` form.
     * @param  array<string, array{shell: string, path: string}>  $shelledResources  resource key → the hand-written layout shell its LIST leaf nests under, and the path RELATIVE TO that shell's mount. A shelled resource emits NO edit twin — its record routes stay hand-written.
     * @param  array<string, string>  $resourcePaths  resource key → a host-chosen flat path, where the URL shipped before the leaf did and the URL is the product decision. The leaf stays under the `app` shell and still emits its `<stem>.edit` twin.
     * @param  array<int, string>  $foldedResources  resource keys that emit NO leaf because their surface is another resource's page. Safe only for a resource absent from the nav — there is no `routeName` join left to satisfy.
     * @param  array<int, string>  $detaillessResources  resource keys that declare `showable` but whose per-record detail THIS host does not serve. `showable` is the server saying `records/{id}` answers; whether this SPA has a page for it is a separate, host-owned fact.
     * @param  array<string, string>  $shellBases  shell key → the path segment it mounts at under the realm's `routeBase`. `''` is the no-shell case. A shell registered client-side and missing here silently drops a segment from every href built off it, so pin this set in a test.
     * @param  array<int, array{routeName: string, path: string, mounts: string, guard?: ?string, shell?: string}>  $centralStandalone  resource-less leaves for a CENTRAL realm ({@see RealmDefinition::$central}) — bespoke pages with no backing frame resource.
     * @param  array<int, array{routeName: string, path: string, mounts: string, guard?: ?string, shell?: string}>  $scopedStandalone  the same, for a non-central (workspace/user/site) realm.
     */
    public function __construct(
        public readonly array $heavyweightEditors = [],
        public readonly array $shelledResources = [],
        public readonly array $resourcePaths = [],
        public readonly array $foldedResources = [],
        public readonly array $detaillessResources = [],
        public readonly array $shellBases = ['app' => ''],
        public readonly array $centralStandalone = [],
        public readonly array $scopedStandalone = [],
    ) {}

    /**
     * The empty plan — every list unset, which is what a host that has spelled nothing out gets.
     * It is a legitimate answer, not a hole: the projector still emits one list leaf (and an
     * edit/detail twin) per realm resource, which is more than any host below the flagship has
     * ever had.
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $config  a host's published `beam.ux.frame_nav.route_context` block
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            heavyweightEditors: (array) ($config['heavyweight_editors'] ?? []),
            shelledResources: (array) ($config['shelled_resources'] ?? []),
            resourcePaths: (array) ($config['resource_paths'] ?? []),
            foldedResources: array_values((array) ($config['folded_resources'] ?? [])),
            detaillessResources: array_values((array) ($config['detailless_resources'] ?? [])),
            shellBases: (array) ($config['shell_bases'] ?? ['app' => '']),
            centralStandalone: array_values((array) ($config['central_standalone'] ?? [])),
            scopedStandalone: array_values((array) ($config['scoped_standalone'] ?? [])),
        );
    }

    /**
     * The resource-less standalone leaves for one realm.
     *
     * The split is on {@see RealmDefinition::$central} rather than on a realm NAME, so a host that
     * contributes a fourth realm gets one of the two lists by its declared shape instead of
     * falling through a branch nobody wrote. A host wanting per-realm standalone lists writes a
     * plan per realm and binds them by key — the projector never assumes there is only one plan.
     *
     * @return array<int, array{routeName: string, path: string, mounts: string, guard?: ?string, shell?: string}>
     */
    public function standaloneFor(RealmDefinition $realm): array
    {
        return $realm->central ? $this->centralStandalone : $this->scopedStandalone;
    }
}
