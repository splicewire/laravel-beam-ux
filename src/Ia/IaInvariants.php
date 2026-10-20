<?php

namespace Splicewire\Beam\Ux\Ia;

/**
 * The IA invariants over one realm's PROJECTED nav (ux-walkthrough SPEC M5, IA-12): they judge the output, whatever
 * builder made it, so a host owns placement and never the partitions.
 *
 * The host/package split {@see \Splicewire\Beam\Ux\Frame\FrameNavContribution::pruneUnbound()} already draws:
 * {@see assert()} throws for a host-authored tree, and {@see prune()} drops the offending node, with its subtree,
 * from a package-contributed one.
 *
 * `$crossings` names realms a rail may link into anyway. It is for a listed exception only, such as the account rail
 * until UX-12b, and it relaxes I1 alone.
 */
class IaInvariants
{
    /** @param  list<NavInvariant>  $invariants */
    public function __construct(protected array $invariants = []) {}

    /** The same invariants plus one more: the slot I2 (UX-08) and I3 (UX-09) arrive through. */
    public function with(NavInvariant $invariant): static
    {
        $clone = clone $this;
        $clone->invariants[] = $invariant;

        return $clone;
    }

    /**
     * @return list<string> the ids of the invariants this instance runs; I7 is a contextual built-in, not a NavInvariant
     *                      strategy, because it compares route-backed seats across the projected tree
     */
    public function ids(): array
    {
        return [...array_map(fn (NavInvariant $invariant): string => $invariant->id(), $this->invariants), 'I7'];
    }

    /**
     * Every violation in the tree: id => why, the id being `<invariant> <realm> <href path>`.
     *
     * @param  array<string, mixed>  $nav  `NavTree::toArray()`, or its bare item list
     * @param  list<string>  $crossings
     * @param  list<\Schemastud\Frame\Registry\RouteContextEntry|array<string, mixed>>  $routeContext
     * @return array<string, string>
     */
    public function violations(string $realm, array $nav, array $crossings = [], array $routeContext = []): array
    {
        $out = [];
        $seenResources = [];
        $this->walk(self::items($nav), [], function (array $node, array $ancestors) use ($realm, $crossings, $routeContext, &$seenResources, &$out): bool {
            foreach ($this->nodeViolations($realm, $node, $ancestors, $crossings, $routeContext, $seenResources) as $id => $why) {
                $out[$id] = $why;
            }

            return true;
        });

        return $out;
    }

    /**
     * Whether a host-authored breach throws. It does where the host is being built: every environment but production,
     * where the guard tests and the author see it. In production route mounting can differ from where the host was built
     * (custody, flags, the sides a host plays), so a link valid there may be unmounted here, and one such link must not
     * take down every page that renders the rail. `beam.ux.ia.throw` overrides either way.
     */
    public function throws(): bool
    {
        return (bool) config('beam.ux.ia.throw', ! app()->isProduction());
    }

    /**
     * Hold a host-authored tree to the invariants: throw where {@see throws()}, otherwise report the breach at error level
     * (naming the host, the realm and each node) and hand back the tree without the breaking nodes, so a pruned link never
     * vanishes silently.
     *
     * @param  array<string, mixed>  $nav
     * @param  list<string>  $crossings
     * @param  list<\Schemastud\Frame\Registry\RouteContextEntry|array<string, mixed>>  $routeContext
     * @return array<string, mixed>
     *
     * @throws IaInvariantViolation
     */
    public function enforce(string $realm, array $nav, array $crossings = [], array $routeContext = []): array
    {
        $violations = $this->violations($realm, $nav, $crossings, $routeContext);

        if ($violations === []) {
            return $nav;
        }

        $violation = new IaInvariantViolation($realm, $violations, self::host());

        if ($this->throws()) {
            throw $violation;
        }

        report($violation);

        return $this->prune($realm, $nav, $crossings, $routeContext);
    }

    /**
     * Throw when a host-authored tree breaks an invariant.
     *
     * @param  array<string, mixed>  $nav
     * @param  list<string>  $crossings
     * @param  list<\Schemastud\Frame\Registry\RouteContextEntry|array<string, mixed>>  $routeContext
     *
     * @throws IaInvariantViolation
     */
    public function assert(string $realm, array $nav, array $crossings = [], array $routeContext = []): void
    {
        $violations = $this->violations($realm, $nav, $crossings, $routeContext);

        if ($violations !== []) {
            throw new IaInvariantViolation($realm, $violations, self::host());
        }
    }

    /**
     * A package-contributed tree without the nodes that break an invariant (each with its subtree), in the shape it
     * came in.
     *
     * @param  array<string, mixed>  $nav
     * @param  list<string>  $crossings
     * @param  list<\Schemastud\Frame\Registry\RouteContextEntry|array<string, mixed>>  $routeContext
     * @return array<string, mixed>
     */
    public function prune(string $realm, array $nav, array $crossings = [], array $routeContext = []): array
    {
        $seenResources = [];
        $kept = $this->keep(self::items($nav), [], $realm, $crossings, $routeContext, $seenResources);

        return array_is_list($nav) ? $kept : [...$nav, 'items' => $kept];
    }

    /** The host whose rail this is: the request's, else the configured app URL's. */
    protected static function host(): string
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request?->getHost() ?: (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * The path of an href this host serves, or null for an empty, fragment-only or external href (one with a scheme
     * or a host): those name no realm and no route here.
     */
    public static function internalPath(mixed $href): ?string
    {
        if (! is_string($href) || $href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $parts = parse_url($href);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '', '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * A seat's section header holding children: a group LABEL, whose href no renderer draws as a link (beam-ux
     * `RealmNav` renders its children under its title). The same exemption {@see \Splicewire\Beam\Ux\Frame\RouteContextValidator}
     * gives it: a section header binds to no leaf. A childless header renders as a lone link and is judged like any node.
     *
     * @param  array<string, mixed>  $node
     */
    public static function isGroupHeader(array $node): bool
    {
        return str_ends_with((string) ($node['routeName'] ?? ''), '.section') && ($node['children'] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $ancestors
     * @param  list<\Schemastud\Frame\Registry\RouteContextEntry|array<string, mixed>>  $routeContext
     * @param  array<string, array{home?: bool, recordTab?: bool}>  $seenResources
     * @param  list<string>  $crossings
     * @return array<string, string>
     */
    protected function nodeViolations(string $realm, array $node, array $ancestors, array $crossings, array $routeContext, array &$seenResources): array
    {
        $out = [];
        $where = self::internalPath($node['href'] ?? null) ?? (string) ($node['routeName'] ?? $node['title'] ?? '?');

        foreach ($this->invariants as $invariant) {
            $why = $invariant->violation($realm, $node, $ancestors, $crossings);
            if ($why !== null) {
                $out["{$invariant->id()} {$realm} {$where}"] = $why;
            }
        }

        // I7 is over glossary objects, not route names. RouteContextEntry::resource is the stable object identity;
        // routeName is only the join from the projected seat to that descriptor. Seats without a matching route-context
        // entry (for example, bespoke HostPages) are outside I7. Doors never enter this tree, and group headers are
        // labels rather than seats. A parameterized resource list is a parent-scoped record tab.
        if ($out === [] && ! self::isGroupHeader($node)) {
            $entry = $this->routeEntry($node, $routeContext);
            $resource = $entry['resource'] ?? null;
            $path = $entry['path'] ?? '';
            $mounts = $entry['mounts'] ?? null;
            $recordTab = $mounts === 'list' && is_string($path)
                && preg_match('/(?:^|\\/)(?::[A-Za-z_][A-Za-z0-9_]*|\\{[A-Za-z_][A-Za-z0-9_]*\\})/', $path) === 1;

            if (is_string($resource) && $resource !== '' && $mounts === 'list') {
                $kind = $recordTab ? 'recordTab' : 'home';

                if ($seenResources[$resource][$kind] ?? false) {
                    $duplicate = $recordTab ? 'parent-scoped record tab' : 'unscoped home seat';
                    $out["I7 {$realm} {$where}"] = "resource [{$resource}] already has a {$duplicate}";
                } else {
                    $seenResources[$resource][$kind] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $ancestors
     * @param  list<string>  $crossings
     * @param  list<\Schemastud\Frame\Registry\RouteContextEntry|array<string, mixed>>  $routeContext
     * @param  array<string, array{home?: bool, recordTab?: bool}>  $seenResources
     * @return list<array<string, mixed>>
     */
    protected function keep(array $nodes, array $ancestors, string $realm, array $crossings, array $routeContext, array &$seenResources): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || $this->nodeViolations($realm, $node, $ancestors, $crossings, $routeContext, $seenResources) !== []) {
                continue;
            }

            $node['children'] = $this->keep($node['children'] ?? [], [...$ancestors, $node], $realm, $crossings, $routeContext, $seenResources);
            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * @param  list<\Schemastud\Frame\Registry\RouteContextEntry|array<string, mixed>>  $routeContext
     * @return array<string, mixed>|null
     */
    private function routeEntry(array $node, array $routeContext): ?array
    {
        $routeName = $node['routeName'] ?? null;
        if (! is_string($routeName) || $routeName === '') {
            return null;
        }

        foreach ($routeContext as $entry) {
            $candidate = is_object($entry) && method_exists($entry, 'toArray') ? $entry->toArray() : $entry;
            if (is_array($candidate) && ($candidate['routeName'] ?? null) === $routeName) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $ancestors
     */
    protected function walk(array $nodes, array $ancestors, callable $visit): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $visit($node, $ancestors);
            $this->walk($node['children'] ?? [], [...$ancestors, $node], $visit);
        }
    }

    /**
     * @param  array<string, mixed>  $nav
     * @return list<array<string, mixed>>
     */
    protected static function items(array $nav): array
    {
        return array_values(array_is_list($nav) ? $nav : ($nav['items'] ?? []));
    }
}
