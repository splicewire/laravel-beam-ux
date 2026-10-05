<?php

namespace Splicewire\Beam\Ux\Ia;

/**
 * The IA invariants over one realm's PROJECTED nav (ux-walkthrough SPEC M5, IA-12): they judge the output, whatever
 * builder made it, so a host owns placement and never the partitions.
 *
 * Two answers to a violation, the split {@see \Splicewire\Beam\Ux\Frame\FrameNavContribution::pruneUnbound()} already
 * draws: {@see assert()} throws for a host-authored tree, and {@see prune()} drops the offending node, with its
 * subtree, from a package-contributed one.
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

    /** @return list<string> the ids of the invariants this instance runs */
    public function ids(): array
    {
        return array_map(fn (NavInvariant $invariant): string => $invariant->id(), $this->invariants);
    }

    /**
     * Every violation in the tree: id => why, the id being `<invariant> <realm> <href path>`.
     *
     * @param  array<string, mixed>  $nav  `NavTree::toArray()`, or its bare item list
     * @param  list<string>  $crossings
     * @return array<string, string>
     */
    public function violations(string $realm, array $nav, array $crossings = []): array
    {
        $out = [];
        $this->walk(self::items($nav), [], function (array $node, array $ancestors) use ($realm, $crossings, &$out): bool {
            foreach ($this->nodeViolations($realm, $node, $ancestors, $crossings) as $id => $why) {
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
     * @return array<string, mixed>
     *
     * @throws IaInvariantViolation
     */
    public function enforce(string $realm, array $nav, array $crossings = []): array
    {
        $violations = $this->violations($realm, $nav, $crossings);

        if ($violations === []) {
            return $nav;
        }

        $violation = new IaInvariantViolation($realm, $violations, self::host());

        if ($this->throws()) {
            throw $violation;
        }

        report($violation);

        return $this->prune($realm, $nav, $crossings);
    }

    /**
     * Throw when a host-authored tree breaks an invariant.
     *
     * @param  array<string, mixed>  $nav
     * @param  list<string>  $crossings
     *
     * @throws IaInvariantViolation
     */
    public function assert(string $realm, array $nav, array $crossings = []): void
    {
        $violations = $this->violations($realm, $nav, $crossings);

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
     * @return array<string, mixed>
     */
    public function prune(string $realm, array $nav, array $crossings = []): array
    {
        $kept = $this->keep(self::items($nav), [], $realm, $crossings);

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
     * @param  list<string>  $crossings
     * @return array<string, string>
     */
    protected function nodeViolations(string $realm, array $node, array $ancestors, array $crossings): array
    {
        $out = [];
        $where = self::internalPath($node['href'] ?? null) ?? (string) ($node['routeName'] ?? $node['title'] ?? '?');

        foreach ($this->invariants as $invariant) {
            $why = $invariant->violation($realm, $node, $ancestors, $crossings);
            if ($why !== null) {
                $out["{$invariant->id()} {$realm} {$where}"] = $why;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $ancestors
     * @param  list<string>  $crossings
     * @return list<array<string, mixed>>
     */
    protected function keep(array $nodes, array $ancestors, string $realm, array $crossings): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || $this->nodeViolations($realm, $node, $ancestors, $crossings) !== []) {
                continue;
            }

            $node['children'] = $this->keep($node['children'] ?? [], [...$ancestors, $node], $realm, $crossings);
            $kept[] = $node;
        }

        return $kept;
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
