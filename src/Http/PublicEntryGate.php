<?php

namespace Splicewire\Beam\Ux\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Access\Right;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;

/**
 * Resolution plus the two gates, in **one place**, because ADR-0209 §5's uniform 404 is a property of
 * the whole triple rather than of any one part: an unresolvable path, an unpublished entry, and a
 * gate-denied entry must be indistinguishable, and they only stay indistinguishable if nothing can
 * answer one of the three questions on its own. The page renderer and the artifact stream both go
 * through here so a guarded body cannot leak through the door the HTML shell does not use.
 *
 * **Gates fire post-resolution and pre-body-read** (§5). No caller of this class has touched the
 * particle store when the answer comes back, so a denied request is both cheaper and free of the timing
 * side channel that would otherwise distinguish "no such entry" from "an entry you may not see".
 *
 * **No second traversal** (ADR-0212 §3): the chain the reverse walk produced is handed straight to
 * {@see EntryAccessResolver::canRender()}, which never queries.
 */
class PublicEntryGate
{
    public function __construct(
        private EntryPathResolver $paths,
        private EntryAccessResolver $access,
        private ?EntryPublishGate $publish = null,
    ) {}

    /**
     * The root-first chain for a public path, or **null for every kind of no** — unresolvable,
     * unpublished, or denied. Callers turn that single null into one 404; there is deliberately no way
     * to ask which of the three it was, because a renderer that answers 403 tells an anonymous reader
     * which private paths exist.
     *
     * @return array<int, BeamUxEntry>|null
     */
    public function chainFor(string $path, string $realm, ?Authenticatable $actor = null): ?array
    {
        $chain = $this->paths->resolve($path, $realm);

        if ($chain === null) {
            return null;
        }

        $target = $chain[array_key_last($chain)];

        if ($this->publish !== null && ! $this->publish->isPublished($target)) {
            return null;
        }

        return $this->access->canRender($actor, $chain) ? $chain : null;
    }

    /**
     * The same triple, for a caller that already has the entry rather than a path — the artifact
     * stream, which is addressed by entry id because a compiled module is not a page and has no
     * segment of its own.
     *
     * The chain is walked UP from the entry rather than resolved from a URL, which is the same ancestry
     * the path walk would have produced: access follows containment, never the URL's pieces (those
     * differ exactly when a root-absolute segment is involved). Realm membership is re-checked here
     * because an entry id is guessable in a way a path is not — without it, the artifact route would be
     * the one door into a realm this mount does not serve.
     *
     * @return array<int, BeamUxEntry>|null
     */
    public function chainForEntry(BeamUxEntry $entry, string $realm, ?Authenticatable $actor = null): ?array
    {
        $realms = $entry->realms ?? [$entry->realm];

        if (! in_array($realm, (array) $realms, true)) {
            return null;
        }

        $chain = [];
        $node = $entry;

        while ($node !== null) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }

        if ($this->publish !== null && ! $this->publish->isPublished($entry)) {
            return null;
        }

        return $this->access->canRender($actor, $chain) ? $chain : null;
    }

    /**
     * Whether anything in the chain declares an access constraint — the question that decides whether
     * this response may be cached.
     *
     * A body reachable only through a gate must be `no-store` (ADR-0209 §8 / ADR-0122), so a revoked
     * grant bites on the next request instead of living on in a shared cache. A body with no
     * declaration anywhere in its ancestry is public by construction and gets ordinary caching — the
     * distinction is worth drawing, because treating every page as private would make a public docs
     * site uncacheable to buy an invariant it never needed.
     *
     * @param  array<int, BeamUxEntry>  $chain
     */
    public function isRestricted(array $chain): bool
    {
        foreach ($chain as $entry) {
            foreach (Right::cases() as $right) {
                if ($entry->tokensFor($right) !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
