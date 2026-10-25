<?php

namespace Splicewire\Beam\Ux\Redirect;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The Redirects a publish would leave (ADR-0236 d2, d4), read before anything changes, so Publish, a Release's
 * readiness report and its Preview all read one plan.
 *
 * A move leaves a Redirect at every URL a guest could open before it (a published page whose chain the publish gate
 * passes, decision 8, and that an anonymous visitor may render, as the renderer decides it) that no page answers for a
 * guest after it, to the page that was there, by identity. A guest is anonymous (EP-31 ruling, orch-lead 23:26Z): a
 * page gated to signed-in readers or requirements leaves none when it moves. That covers the moved
 * pages and every page under them whose URL changed, composed with all of the publish's moves at once, as
 * {@see \Splicewire\Beam\Ux\Publish\EntryUrlChanges} composes them. A swap, a new page at an old URL, a root-absolute
 * segment and a hidden page leave none. A Record Page that moves leaves a path-and-below Redirect from its old static
 * prefix to its new one, carrying the Record's segment (EP-30/31 ruling 2). A Take-down sends every URL it hides (its
 * page's and those under it) to its target, by identity; without one they answer the uniform 404.
 *
 * The plan also names an exact Redirect a page will take the URL of, which Publish removes (d7), and a page that
 * publishes under a page hidden after it, which stays hidden (d8).
 */
final class ReleaseRedirects
{
    public function __construct(
        private UrlResolver $urls,
        private VersionStore $versions,
        private EntryAccessResolver $access,
        private ?EntryPublishGate $gate = null,
    ) {}

    /**
     * `to` is where each leads once the publish runs (the target page's URL after it), which a Preview serves and the
     * bar names. `gone` lists the URLs a Take-down with no target hides: the uniform 404 (d4).
     *
     * @param  array<string, string>  $refs  the versions the publish puts live, by entry id
     * @param  array<string, ?string>  $takedowns  the entries it takes down, each with its target (an id or a URL) or null
     * @return array{
     *     redirects: list<array{realm: string, path: string, match: string, targetKind: string, targetEntryId: ?string, targetPath: ?string, to: ?string, reason: string, entryId: string}>,
     *     gone: list<array{realm: string, path: string, entryId: string}>,
     *     replaced: list<array{realm: string, path: string, redirectId: string, entryId: string}>,
     *     staysHidden: list<string>
     * }
     */
    public function plan(BeamUxEntry $context, array $refs, array $takedowns = []): array
    {
        [$now, $after, $publishing, $removed, $visibleAfter] = $this->graph($context, $refs, $takedowns);
        $before = $this->addresses($now, fn (BeamUxEntry $entry) => $entry->published_version !== null && $this->published($entry));
        $afterUrls = $this->addresses($after, fn (BeamUxEntry $entry) => isset($publishing[(string) $entry->getKey()]) || $entry->published_version !== null, $visibleAfter);

        $answered = [];
        $redirects = [];
        $gone = [];
        foreach ($before as $key => $was) {
            [$realm, $id] = explode('|', $key, 2);
            $entry = $now[$id];
            $is = $afterUrls[$key] ?? null;
            if ($is !== null && $is === $was) {
                continue;
            }
            if (RecordPages::parameter($entry->segment) !== null) {
                // A Record Page moves as a pattern: from its old static prefix to its new one, the Record's segment
                // carried. Taken down, its URLs are a pattern no single target page answers, so they are the 404.
                $from = $this->prefixOf($was);
                $to = $is === null ? null : $this->prefixOf($is);
                if ($from !== null && $to !== null && $from !== $to) {
                    $redirects[] = $this->row($realm, $from, 'prefix', 'path', null, $to, $to, 'moved', $id);
                }

                continue;
            }
            if (in_array($was, $answered[$realm] ??= $this->urlsIn($afterUrls, $realm), true)) {
                continue;
            }
            if ($is !== null) {
                $redirects[] = $this->row($realm, $was, 'exact', 'page', $id, null, $is, 'moved', $id);

                continue;
            }
            $takenDown = $this->takenDownAbove($after, $id, $removed);
            if ($takenDown === null) {
                continue;
            }
            $target = $this->target($now, $removed[$takenDown]);
            $target === null
                ? $gone[] = ['realm' => $realm, 'path' => $was, 'entryId' => $id]
                : $redirects[] = $this->row($realm, $was, 'exact', 'page', $target, null, $afterUrls[$realm.'|'.$target] ?? null, 'takedown', $id);
        }

        return [
            'redirects' => $redirects,
            'gone' => $gone,
            'replaced' => $this->replaced($context, $after, $afterUrls, $publishing),
            'staysHidden' => array_values(array_filter(array_keys($publishing), fn (string $id) => $after->has($id)
                && $after[$id]->type === UxType::Page && ! array_key_exists((string) $id, $removed)
                && ! $this->chainVisible($after, $id, $visibleAfter))),
        ];
    }

    /** The plan of a Release: its members' versions, and its Take-downs with their targets. */
    public function forRelease(BeamUxRelease $release): array
    {
        $members = $release->members()->get();

        return $this->plan((new BeamUxEntry)->setConnection($release->getConnectionName()),
            $members->whereNotNull('version_id')->mapWithKeys(fn ($m) => [(string) $m->entry_id => (string) $m->version_id])->all(),
            $members->whereNull('version_id')->mapWithKeys(fn ($m) => [(string) $m->entry_id => $m->redirect_target])->all());
    }

    /** The plan a Preview shows: its Release's, or the one saved version a single-entry Preview holds. */
    public function forPreview(PreviewContext $preview): array
    {
        $grant = $preview->grant;
        if ($grant->release_id !== null && $grant->release !== null) {
            return $this->forRelease($grant->release);
        }
        $prototype = (new BeamUxEntry)->setConnection($grant->getConnectionName());

        return $grant->entry_id !== null && $grant->version_id !== null
            ? $this->plan($prototype, [(string) $grant->entry_id => (string) $grant->version_id])
            : ['redirects' => [], 'gone' => [], 'replaced' => [], 'staysHidden' => []];
    }

    /**
     * Whether a page is live once the publish runs (EP-31 ruling 4): published now or by it, not taken down by it, and
     * not under a page hidden after it. A Take-down's target must be (d4).
     *
     * @param  array<string, string>  $refs
     * @param  array<string, ?string>  $takedowns
     */
    public function liveAfter(BeamUxEntry $context, array $refs, array $takedowns, string $entryId): bool
    {
        [, $after, $publishing, , $visibleAfter] = $this->graph($context, $refs, $takedowns);
        $entry = $after->get($entryId);

        return $entry !== null && (isset($publishing[$entryId]) || $entry->published_version !== null)
            && $this->chainVisible($after, $entryId, $visibleAfter);
    }

    /** A Take-down's target, named by its id or by its URL now, as the entry it is. */
    public function targetOf(BeamUxEntry $context, ?string $named): ?string
    {
        return $this->target($context->newQuery()->get()->keyBy(fn (BeamUxEntry $entry) => (string) $entry->getKey()), $named);
    }

    /**
     * The entries now, and after the publish's moves; the entries it publishes and takes down; and the gate a node of a
     * chain passes after it (a publishing page counts as published, a taken-down one as hidden).
     *
     * @return array{0: Collection<string, BeamUxEntry>, 1: Collection<string, BeamUxEntry>, 2: array<string, true>, 3: array<string, ?string>, 4: \Closure(BeamUxEntry): bool}
     */
    private function graph(BeamUxEntry $context, array $refs, array $takedowns): array
    {
        $now = $context->newQuery()->get()->keyBy(fn (BeamUxEntry $entry) => (string) $entry->getKey());
        $after = $now->map(fn (BeamUxEntry $entry) => clone $entry);
        foreach ($refs as $id => $ref) {
            $change = $this->change($now->get((string) $id), (string) $ref);
            if ($change !== null && $after->has((string) $id)) {
                $after[(string) $id]->parent_id = $change['parentId'];
                $after[(string) $id]->segment = $change['segment'];
            }
        }
        $publishing = array_fill_keys(array_map('strval', array_keys($refs)), true);
        $removed = [];
        foreach ($takedowns as $id => $target) {
            $removed[(string) $id] = $target;
        }
        $visibleAfter = function (BeamUxEntry $entry) use ($publishing, $removed): bool {
            $id = (string) $entry->getKey();

            return ! array_key_exists($id, $removed) && (isset($publishing[$id]) || $this->published($entry));
        };

        return [$now, $after, $publishing, $removed, $visibleAfter];
    }

    /** The URL change a saved version's page settings propose, as Publish reads them. */
    private function change(?BeamUxEntry $entry, string $ref): ?array
    {
        if ($entry === null || $entry->particle === null) {
            return null;
        }
        $settings = $this->versions->version($entry->particle, $ref)?->snapshot['meta']['pageSettings'] ?? null;

        return is_array($settings) ? ['parentId' => $settings['parentId'] ?? null, 'segment' => $settings['segment'] ?? null] : null;
    }

    /**
     * Each page's URL in each of its realms, where it has a URL a guest could open: it qualifies itself, every page of
     * its chain passes the gate (decision 8), and an anonymous visitor may render it (`traverse` and `access`, as
     * {@see \Splicewire\Beam\Ux\Http\PublicEntryGate::chainFor()} decides for a null actor).
     *
     * @param  Collection<string, BeamUxEntry>  $entries
     * @param  callable(BeamUxEntry): bool  $live  whether the page itself has a live version
     * @param  callable(BeamUxEntry): bool|null  $visible  the gate for a node of the chain
     * @return array<string, string> `realm|id` => URL
     */
    private function addresses(Collection $entries, callable $live, ?callable $visible = null): array
    {
        $visible ??= fn (BeamUxEntry $entry) => $this->published($entry);
        $out = [];
        foreach ($entries as $id => $entry) {
            if ($entry->type !== UxType::Page || $entry->segment === null || $entry->segment === '' || ! $live($entry)) {
                continue;
            }
            $chain = $this->chain($entries, (string) $id);
            if ($chain === null || in_array(false, array_map($visible, $chain), true) || ! $this->access->canRender(null, $chain)) {
                continue;
            }
            $url = $this->urls->resolveChain($chain);
            foreach ((array) ($entry->realms ?? [$entry->realm]) as $realm) {
                $out[$realm.'|'.$id] = $url;
            }
        }

        return $out;
    }

    /** @param Collection<string, BeamUxEntry> $entries @return list<BeamUxEntry>|null root-first, or null on a cycle or a missing parent */
    private function chain(Collection $entries, string $id): ?array
    {
        $chain = [];
        $seen = [];
        for ($node = $entries->get($id); $node !== null; $node = $node->parent_id === null ? null : $entries->get((string) $node->parent_id)) {
            if (isset($seen[(string) $node->getKey()])) {
                return null;
            }
            $seen[(string) $node->getKey()] = true;
            array_unshift($chain, $node);
            if ($node->parent_id !== null && ! $entries->has((string) $node->parent_id)) {
                return null;
            }
        }

        return $chain;
    }

    private function chainVisible(Collection $entries, string $id, callable $visible): bool
    {
        $chain = $this->chain($entries, $id);

        return $chain !== null && ! in_array(false, array_map($visible, $chain), true);
    }

    /**
     * The URLs pages answer at after the publish, in one realm. A Record Page's pattern isn't one: whether a Record
     * answers a URL is the renderer's to find, and a Record that does always wins over a Redirect there (d7).
     *
     * @return list<string>
     */
    private function urlsIn(array $afterUrls, string $realm): array
    {
        $out = [];
        foreach ($afterUrls as $key => $url) {
            if (str_starts_with($key, $realm.'|') && ! str_contains($url, '{')) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /** The nearest page at or above this one that the publish takes down, in the graph after it. */
    private function takenDownAbove(Collection $after, string $id, array $removed): ?string
    {
        foreach (array_reverse($this->chain($after, $id) ?? []) as $node) {
            if (array_key_exists((string) $node->getKey(), $removed)) {
                return (string) $node->getKey();
            }
        }

        return null;
    }

    /** A Take-down's target, named by id or by its URL now, as the page it is: a Take-down redirects to a page (d4). */
    private function target(Collection $now, ?string $named): ?string
    {
        if ($named === null || $named === '') {
            return null;
        }
        if ($now->has($named)) {
            return $now[$named]->type === UxType::Page ? $named : null;
        }
        $match = $now->first(fn (BeamUxEntry $entry) => $entry->type === UxType::Page && $entry->segment !== null
            && ($chain = $this->chain($now, (string) $entry->getKey())) !== null && $this->urls->resolveChain($chain) === $named);

        return $match === null ? null : (string) $match->getKey();
    }

    /**
     * The static prefix of a Record Page's URL, when its parameter is its last segment. A Record Page at the site's root
     * has none: a path-and-below Redirect from / would take every URL.
     */
    private function prefixOf(string $url): ?string
    {
        return preg_match('#^(/.+)/\{[^/]+\}$#D', $url, $match) === 1 ? $match[1] : null;
    }

    /**
     * Exact Redirects at the URLs the publish's own pages take: Publish removes each (d7).
     *
     * @return list<array{realm: string, path: string, redirectId: string, entryId: string}>
     */
    private function replaced(BeamUxEntry $context, Collection $after, array $afterUrls, array $publishing): array
    {
        if (! Schema::connection($context->getConnectionName())->hasTable('beam_ux_redirects')) {
            return [];
        }
        $out = [];
        foreach ($afterUrls as $key => $url) {
            [$realm, $id] = explode('|', $key, 2);
            if (! isset($publishing[$id]) || RecordPages::parameter($after[$id]->segment) !== null) {
                continue;
            }
            foreach (BeamUxRedirect::on($context->getConnectionName())->where('realm', $realm)->where('match', 'exact')->where('path', $url)->get() as $redirect) {
                $out[] = ['realm' => $realm, 'path' => $url, 'redirectId' => (string) $redirect->getKey(), 'entryId' => $id];
            }
        }

        return $out;
    }

    private function published(BeamUxEntry $entry): bool
    {
        return $this->gate === null || $this->gate->isPublished($entry);
    }

    private function row(string $realm, string $path, string $match, string $kind, ?string $entryTarget, ?string $pathTarget, ?string $to, string $reason, string $entryId): array
    {
        return ['realm' => $realm, 'path' => $path, 'match' => $match, 'targetKind' => $kind, 'targetEntryId' => $entryTarget,
            'targetPath' => $pathTarget, 'to' => $to, 'reason' => $reason, 'entryId' => $entryId];
    }
}
