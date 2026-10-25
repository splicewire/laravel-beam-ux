<?php

namespace Splicewire\Beam\Ux\Containment;

use Illuminate\Support\Collection;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Data\ReachData;
use Splicewire\Beam\Ux\Data\ReachPageData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * **Reach**: the live pages an entry changes (ADR-0232 d10, and ADR-0231 d16's "PricingCard — used on 12 pages").
 *
 * - A page reaches itself.
 * - A layout or template reaches every page whose containment chain resolves to it ({@see ChromeResolver}: the nearest
 *   declaring page wins), by its slug.
 * - An Authored Component reaches every page that uses it, directly or through another component, layout or template
 *   ({@see AuthoredComponents::usedBy()}, which reads every body an entry retains).
 * - A theme reaches every page.
 *
 * Read in the tree a Preview shows when one is given (the Release's page settings, its Take-downs gone), else the live
 * tree. A page counts when it is live, or a Preview's Release will publish it.
 */
final class Reach
{
    public function __construct(
        private AuthoredComponents $components,
        private ChromeResolver $chrome = new ChromeResolver,
        private UrlResolver $urls = new UrlResolver,
    ) {}

    public function of(BeamUxEntry $entry, ?PreviewContext $preview = null): ReachData
    {
        $tree = $this->tree($entry, $preview);
        if ($entry->type === UxType::Theme) {
            return new ReachData(true, count($tree['live']), []);
        }
        $ids = match (true) {
            $entry->type === UxType::Page => [(string) $entry->getKey()],
            $entry->type === UxType::Layout => $this->chromePages($tree, 'layout', (string) $entry->slug),
            $entry->type === UxType::Template => $this->chromePages($tree, 'template', (string) $entry->slug),
            $this->components->authored($entry) => $this->componentPages($entry, $tree),
            default => [],
        };
        $pages = [];
        foreach (array_unique($ids) as $id) {
            if (isset($tree['live'][$id])) {
                $page = $tree['pages'][$id];
                $pages[] = new ReachPageData($id, $page->title ?? $page->slug, $tree['paths'][$id],
                    $page->getAttribute('record_page') !== null);
            }
        }
        usort($pages, fn (ReachPageData $a, ReachPageData $b) => [$a->path ?? "\u{10FFFF}", $a->entryId] <=> [$b->path ?? "\u{10FFFF}", $b->entryId]);

        return new ReachData(false, count($pages), $pages);
    }

    /**
     * The realm's pages, projected through the Preview, with each one's chain, path and liveness.
     *
     * @return array{pages: Collection<string, BeamUxEntry>, chains: array<string, list<BeamUxEntry>>, paths: array<string, ?string>, live: array<string, true>}
     */
    private function tree(BeamUxEntry $entry, ?PreviewContext $preview): array
    {
        $pages = $entry->newQuery()->where('type', UxType::Page->value)->whereJsonContains('realms', $entry->realm)->get()
            ->map(fn (BeamUxEntry $page) => $preview === null ? $page : $preview->project($page))
            ->filter()->keyBy(fn (BeamUxEntry $page) => (string) $page->getKey());
        $gate = app(EntryPublishGate::class);
        $chains = $paths = $live = [];
        foreach ($pages as $id => $page) {
            $chain = [];
            $seen = [];
            for ($node = $page; $node !== null; $node = $node->parent_id === null ? null : $pages->get((string) $node->parent_id)) {
                if (isset($seen[$node->getKey()])) {
                    $chain = [];
                    break;
                }
                $seen[$node->getKey()] = true;
                array_unshift($chain, $node);
            }
            $chains[$id] = $chain;
            $paths[$id] = $chain === [] || $chain[0]->parent_id !== null ? null : $this->urls->resolveChain($chain);
            if ($preview?->admitsDraft($id) || ($page->published_version !== null && $gate->isPublished($page))) {
                $live[$id] = true;
            }
        }

        return ['pages' => $pages, 'chains' => $chains, 'paths' => $paths, 'live' => $live];
    }

    /** @return list<string> */
    private function chromePages(array $tree, string $axis, string $name): array
    {
        $ids = [];
        foreach ($tree['chains'] as $id => $chain) {
            if ($chain !== [] && $this->chrome->resolve($chain)[$axis] === $name) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private function componentPages(BeamUxEntry $component, array $tree): array
    {
        $ids = [];
        $visited = [(string) $component->getKey() => true];
        $pending = [$component];
        while (($current = array_pop($pending)) !== null) {
            foreach ($this->components->usedBy($current) as $userId) {
                if (isset($visited[$userId])) {
                    continue;
                }
                $visited[$userId] = true;
                $user = $component->newQuery()->find($userId);
                match (true) {
                    $user === null => null,
                    $user->type === UxType::Page => $ids[] = $userId,
                    $user->type === UxType::Layout => array_push($ids, ...$this->chromePages($tree, 'layout', (string) $user->slug)),
                    $user->type === UxType::Template => array_push($ids, ...$this->chromePages($tree, 'template', (string) $user->slug)),
                    $this->components->authored($user) => $pending[] = $user,
                    default => null,
                };
            }
        }

        return $ids;
    }
}
