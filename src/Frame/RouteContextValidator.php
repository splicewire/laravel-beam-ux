<?php

namespace Splicewire\Beam\Ux\Frame;

use InvalidArgumentException;
use Rushing\DataNav\NavNode;
use Rushing\DataNav\NavTree;
use Schemastud\Frame\Registry\RouteContextEntry;

/**
 * The boot invariants of the router half of the manifest, enforced at emit. `routeName` is a
 * global namespace, so a duplicate or unbound name must fail loudly — the same discipline the
 * widget registry keeps.
 *
 *  - **UNIQUE**  — no `routeName` appears twice across the RouteContext.
 *  - **BOUND**   — every nav node carrying a `routeName` is backed by a RouteContext entry of the
 *                  same name. A nav item pointing nowhere is a config bug.
 *  - **FLAT**    — a RouteContext entry carries no child entries; nesting is hand-written.
 *
 * ## Why these three throw, when this file's own estate rules say host-dependent checks must not
 *
 * AGENTS.md draws the line at *"what the declaration's author could have gotten right without
 * knowing which host would load it."* All three are exactly that: a duplicate name, a nav seat
 * pointing at a leaf nobody emits, and a non-flat mount are grammar errors in the host's OWN plan,
 * discoverable from the plan alone. They are not questions like *"is this resource registered
 * here?"*, which is a fact about the host and stays advisory. The realm-level question — *does
 * this host have a navigation for this realm at all* — is answered by returning null one layer up
 * in {@see FrameNavContribution}, never by throwing here.
 */
class RouteContextValidator
{
    /**
     * @param  array<int, RouteContextEntry>  $routeContext
     *
     * @throws InvalidArgumentException on a duplicate, unbound, or nested route.
     */
    public function assert(array $routeContext, NavTree $nav): void
    {
        $names = [];

        foreach ($routeContext as $entry) {
            $this->assertFlat($entry);

            if (isset($names[$entry->routeName])) {
                throw new InvalidArgumentException(
                    "Duplicate frame routeName [{$entry->routeName}] in the RouteContext — every route identity must be unique."
                );
            }

            $names[$entry->routeName] = true;
        }

        foreach ($this->navRouteNames($nav) as $routeName) {
            // Section nodes (`*.section`) are nav-only headers with no leaf; only resource / page
            // nodes must bind to a RouteContext entry.
            if (str_ends_with($routeName, '.section')) {
                continue;
            }

            if (! isset($names[$routeName])) {
                throw new InvalidArgumentException(
                    "Nav node routeName [{$routeName}] is unbound — no RouteContext entry provides it."
                );
            }
        }
    }

    /**
     * A RouteContext entry must be flat. Any structure that would carry a child route table is
     * rejected — nesting is the deliberately deferred structural problem and stays hand-written.
     */
    protected function assertFlat(RouteContextEntry $entry): void
    {
        if (! in_array($entry->mounts, ['list', 'edit', 'detail', 'widget', 'redirect'], true)) {
            throw new InvalidArgumentException(
                "RouteContext entry [{$entry->routeName}] has a non-flat mount [{$entry->mounts}] — RouteContext is flat; nesting is hand-written."
            );
        }
    }

    /**
     * The routeNames declared anywhere in the resolved nav tree (recursively).
     *
     * @return array<int, string>
     */
    protected function navRouteNames(NavTree $nav): array
    {
        $names = [];

        $walk = function (NavNode $node) use (&$walk, &$names): void {
            if ($node->routeName !== null) {
                $names[] = $node->routeName;
            }

            foreach ($node->children() as $child) {
                $walk($child);
            }
        };

        foreach ($nav->items as $node) {
            $walk($node);
        }

        return $names;
    }
}
