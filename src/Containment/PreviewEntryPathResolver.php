<?php

namespace Splicewire\Beam\Ux\Containment;

use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Type\UxType;

/** Resolves a path against the frozen page settings of a verified Preview, never against drafts globally. */
final class PreviewEntryPathResolver
{
    public function __construct(private UrlResolver $urls = new UrlResolver) {}

    /** @return list<BeamUxEntry>|null Root-first projected chain. */
    public function resolve(string $path, string $realm, PreviewContext $preview): ?array
    {
        $entries = $this->entriesFor($realm, $preview);

        $expected = '/'.trim($path, '/');
        if ($expected === '/') {
            $expected = '/';
        }

        $parameterMatches = [];
        foreach ($entries as $entry) {
            $chain = [];
            $node = $entry;
            $seen = [];
            while ($node !== null) {
                $id = (string) $node->getKey();
                if (isset($seen[$id])) {
                    $chain = [];
                    break;
                }
                $seen[$id] = true;
                $chain[] = $node;
                $parentId = $node->parent_id;
                $node = $parentId === null ? null : $entries->get((string) $parentId);
                if ($parentId !== null && $node === null) {
                    $chain = [];
                    break;
                }
            }

            if ($chain === []) {
                continue;
            }

            $chain = array_reverse($chain);
            if ($this->urls->resolveChain($chain) === $expected) {
                return $chain;
            }
            $field = \Splicewire\Beam\Ux\Bindings\RecordPages::parameter($entry->segment);
            if ($field !== null) {
                $pattern = str_replace(preg_quote('{'.$field.'}', '#'), '([^/]+)', preg_quote($this->urls->resolveChain($chain), '#'));
                if (preg_match('#^'.$pattern.'$#D', $expected, $match) === 1) {
                    $entry->setAttribute('_record_page_value', rawurldecode($match[1]));
                    $parameterMatches[] = $chain;
                }
            }
        }

        return count($parameterMatches) === 1 ? $parameterMatches[0] : null;
    }

    public function pathForEntry(string $entryId, string $realm, PreviewContext $preview): ?string
    {
        $entries = $this->entriesFor($realm, $preview);
        $entry = $entries->get($entryId);
        if (! $entry instanceof BeamUxEntry) {
            return null;
        }

        $chain = [];
        $node = $entry;
        $seen = [];
        while ($node !== null) {
            $id = (string) $node->getKey();
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $chain[] = $node;
            $parentId = $node->parent_id;
            $node = $parentId === null ? null : $entries->get((string) $parentId);
            if ($parentId !== null && $node === null) {
                return null;
            }
        }

        return $this->urls->resolveChain(array_reverse($chain));
    }

    /** @return list<BeamUxEntry>|null Root-first projected ancestry for a page or chrome artifact. */
    public function chainForEntry(string $entryId, string $realm, PreviewContext $preview): ?array
    {
        $entries = $this->entriesFor($realm, $preview, pagesOnly: false);
        $entry = $entries->get($entryId);
        if (! $entry instanceof BeamUxEntry) {
            return null;
        }

        $chain = [];
        $node = $entry;
        $seen = [];
        while ($node !== null) {
            $id = (string) $node->getKey();
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $chain[] = $node;
            $parentId = $node->parent_id;
            $node = $parentId === null ? null : $entries->get((string) $parentId);
            if ($parentId !== null && $node === null) {
                return null;
            }
        }

        return array_reverse($chain);
    }

    /** @return \Illuminate\Support\Collection<string, BeamUxEntry> */
    private function entriesFor(string $realm, PreviewContext $preview, bool $pagesOnly = true): \Illuminate\Support\Collection
    {
        $query = BeamUxEntry::query()->whereJsonContains('realms', $realm);
        if ($pagesOnly) {
            $query->where('type', UxType::Page->value);
        }

        return $query
            ->get()
            ->map(fn (BeamUxEntry $entry) => $preview->project($entry))
            ->filter()
            ->keyBy(fn (BeamUxEntry $entry) => (string) $entry->getKey());
    }
}
