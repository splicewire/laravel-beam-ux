<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * A hidden page with published pages under it (ADR-0236 d8 and Consequences). A hidden page hides the pages under it,
 * on the renderer, the artifact route and the sitemap alike, so a structural page left unpublished takes its published
 * subtree off the site without a word. This names each one, with how many published pages it hides, so it is found
 * before readers notice. Each page keeps its own marking: publishing the hidden one brings the subtree back.
 */
final class BeamUxHiddenParentAudit implements DoctorAudit
{
    public const CHECK = 'no hidden page hides published pages under it';

    public function __construct(private UrlResolver $urls, private ?EntryPublishGate $gate = null) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $table = (new BeamUxEntry)->getTable();
        if (! Schema::hasTable($table)) {
            return [Finding::inconclusive(self::CHECK, "{$table} is absent: publish + migrate beam-ux before auditing pages.")];
        }
        if ($this->gate === null) {
            return [Finding::pass(self::CHECK, 'no publish gate is bound, so no page is hidden.')];
        }
        $pages = BeamUxEntry::query()->where('type', UxType::Page->value)->get();
        $children = $pages->groupBy(fn (BeamUxEntry $page) => (string) $page->parent_id);
        $found = [];
        foreach ($pages as $page) {
            if ($this->gate->isPublished($page)) {
                continue;
            }
            $hidden = 0;
            $stack = [(string) $page->getKey()];
            $seen = [];
            while (($id = array_pop($stack)) !== null) {
                foreach ($children->get($id, []) as $child) {
                    if (isset($seen[(string) $child->getKey()])) {
                        continue;
                    }
                    $seen[(string) $child->getKey()] = true;
                    $stack[] = (string) $child->getKey();
                    if ($child->published_version !== null && $this->gate->isPublished($child)) {
                        $hidden++;
                    }
                }
            }
            if ($hidden > 0) {
                $found[] = $this->urls->resolve($page)." ({$hidden} published page".($hidden === 1 ? '' : 's').' under it)';
            }
        }
        if ($found === []) {
            return [Finding::pass(self::CHECK, 'no hidden page has published pages under it.')];
        }
        sort($found);
        $shown = array_slice($found, 0, 5);
        $rest = count($found) - count($shown);

        return [Finding::warn(self::CHECK, count($found).' hidden page(s) hide the published pages under them, which no reader can open '
            .'until the page above them is published: '.implode('; ', $shown).($rest > 0 ? " (+{$rest} more)" : '').'.')];
    }
}
