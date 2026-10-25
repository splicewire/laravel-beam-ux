<?php

namespace Splicewire\Beam\Ux\Containment;

use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Data\ReachData;
use Splicewire\Beam\Ux\Data\ReachPageData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * One realm, read once, for {@see Reach}: its pages with their chains and paths, and which components each live page,
 * component, layout and template uses in the body it renders. That body is a Preview's selected version for a member
 * of its Release (its page settings applied, a Take-down gone), else the published one; never a working draft or
 * another Release's version, which {@see AuthoredComponents::usedBy()} keeps for delete safety.
 *
 * Its queries don't grow with the site: the realm's entries in one, their published bodies in one, the Release's
 * members in one, and each member's projection.
 */
final class ReachMap
{
    /** @var array<string, BeamUxEntry> every entry read, as rendered */
    private array $entries = [];

    /** @var array<string, true> entries that are live, or that the Preview's Release will publish */
    private array $live = [];

    /** @var array<string, list<BeamUxEntry>> each page's chain, root first; empty when it is broken */
    private array $chains = [];

    /** @var array<string, ?string> */
    private array $paths = [];

    /** @var array<string, string> each live Authored Component's name, as rendered */
    private array $componentNames = [];

    /** @var array<string, list<string>> component name => the live entries whose rendered body uses it */
    private array $users = [];

    public function __construct(
        string $realm,
        ?string $connection,
        ?PreviewContext $preview,
        private AuthoredComponents $components,
        private ChromeResolver $chrome = new ChromeResolver,
        private UrlResolver $urls = new UrlResolver,
    ) {
        $selected = self::selected($preview);
        $entries = BeamUxEntry::on($connection)->whereIn('type', ['page', 'component', 'layout', 'template'])
            ->whereJsonContains('realms', $realm)->with('particle')->get();
        $pins = [];
        foreach ($entries as $entry) {
            if (! array_key_exists((string) $entry->getKey(), $selected) && $entry->published_version !== null && $entry->particle !== null) {
                $pins[] = (string) $entry->published_version;
            }
        }
        $published = $pins === [] ? collect() : Version::on($connection)->whereKey($pins)->get()->keyBy(fn (Version $v) => (string) $v->getKey());
        $gate = app(EntryPublishGate::class);
        foreach ($entries as $entry) {
            $id = (string) $entry->getKey();
            if (array_key_exists($id, $selected)) {
                $entry = $selected[$id] === null ? null : $preview->project($entry);
                if ($entry === null) {
                    continue;
                }
                $body = $entry->particle?->payload;
                $live = true;
            } else {
                $version = $entry->published_version === null ? null : $published->get((string) $entry->published_version);
                // A pin belongs to the entry's own history, as EloquentVersionStore scopes it.
                $owned = $version !== null && $entry->particle !== null && $version->versionable_id === (string) $entry->particle->getKey()
                    && $version->versionable_type === $entry->particle->getMorphClass();
                $body = $owned ? ($version->snapshot['payload'] ?? null) : null;
                $live = $entry->published_version !== null && $gate->isPublished($entry);
            }
            $this->entries[$id] = $entry;
            if (! $live) {
                continue;
            }
            $this->live[$id] = true;
            foreach (is_array($body) ? $this->components->names($body) : [] as $name) {
                $this->users[$name][] = $id;
            }
            if ($this->components->authored($entry)) {
                $name = array_key_exists($id, $selected) ? $this->definedName($body) : $entry->getAttribute('published_component_name');
                if (is_string($name) && $name !== '') {
                    $this->componentNames[$id] = $name;
                }
            }
        }
        foreach ($this->entries as $id => $entry) {
            if ($entry->type === UxType::Page) {
                $this->chains[$id] = $this->chain($entry);
                $this->paths[$id] = $this->chains[$id] === [] ? null : $this->urls->resolveChain($this->chains[$id]);
            }
        }
    }

    public function of(BeamUxEntry $entry): ReachData
    {
        $id = (string) $entry->getKey();
        if ($entry->type === UxType::Theme) {
            return new ReachData(true, count(array_intersect_key($this->live, $this->chains)), []);
        }
        $ids = match (true) {
            $entry->type === UxType::Page => [$id],
            $entry->type === UxType::Layout => $this->chromePages('layout', (string) $entry->slug),
            $entry->type === UxType::Template => $this->chromePages('template', (string) $entry->slug),
            $this->components->authored($entry) => $this->componentPages($id),
            default => [],
        };
        $pages = [];
        foreach (array_unique($ids) as $pageId) {
            if (isset($this->live[$pageId], $this->chains[$pageId])) {
                $page = $this->entries[$pageId];
                $pages[] = new ReachPageData($pageId, $page->title ?? $page->slug, $this->paths[$pageId], $page->getAttribute('record_page') !== null);
            }
        }
        usort($pages, fn (ReachPageData $a, ReachPageData $b) => [$a->path ?? "\u{10FFFF}", $a->entryId] <=> [$b->path ?? "\u{10FFFF}", $b->entryId]);

        return new ReachData(false, count($pages), $pages);
    }

    /** @return list<string> the pages whose chain resolves this layout or template, by its slug */
    private function chromePages(string $axis, string $name): array
    {
        $ids = [];
        foreach ($this->chains as $id => $chain) {
            if ($chain !== [] && $this->chrome->resolve($chain)[$axis] === $name) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<string> the pages that render the component, directly or through a component, layout or template */
    private function componentPages(string $componentId): array
    {
        $name = $this->componentNames[$componentId] ?? null;
        if ($name === null) {
            return [];
        }
        $ids = [];
        $seen = [$name => true];
        $pending = [$name];
        while (($current = array_pop($pending)) !== null) {
            foreach ($this->users[$current] ?? [] as $userId) {
                $user = $this->entries[$userId];
                if ($user->type === UxType::Page) {
                    $ids[] = $userId;
                } elseif ($user->type === UxType::Layout || $user->type === UxType::Template) {
                    array_push($ids, ...$this->chromePages($user->type === UxType::Layout ? 'layout' : 'template', (string) $user->slug));
                } elseif (($next = $this->componentNames[$userId] ?? null) !== null && ! isset($seen[$next])) {
                    $seen[$next] = true;
                    $pending[] = $next;
                }
            }
        }

        return $ids;
    }

    /** @return list<BeamUxEntry> root first; empty when a parent is missing or the parents cycle */
    private function chain(BeamUxEntry $page): array
    {
        $chain = [];
        for ($node = $page; $node !== null; $node = $this->entries[(string) $node->parent_id] ?? null) {
            if (in_array($node, $chain, true)) {
                return [];
            }
            array_unshift($chain, $node);
            if ($node->parent_id === null) {
                return $chain;
            }
        }

        return [];
    }

    private function definedName(mixed $body): ?string
    {
        try {
            return is_array($body) ? $this->components->definition($body)->name : null;
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * The versions a Preview selects, by entry id: its Release's members (null for a Take-down), or the one entry a
     * version preview names. Read once.
     *
     * @return array<string, ?string>
     */
    private static function selected(?PreviewContext $preview): array
    {
        if ($preview === null) {
            return [];
        }
        $grant = $preview->grant;
        if ($grant->release_id === null) {
            return [(string) $grant->entry_id => $grant->version_id === null ? null : (string) $grant->version_id];
        }

        return $grant->release?->members()->get()
            ->mapWithKeys(fn ($member) => [(string) $member->entry_id => $member->version_id === null ? null : (string) $member->version_id])
            ->all() ?? [];
    }
}
