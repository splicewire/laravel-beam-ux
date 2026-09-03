<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * **A composed URL is not evidence of reachability.** This audit is the standing instrument for
 * beam-docs-satellite ticket 69, where that sentence cost a session and a corpus.
 *
 * At `splicewire/www` fifteen published docs guides had their URLs repaired, and
 * {@see UrlResolver::resolve()} duly reported every corrected URL — while
 * {@see EntryPathResolver::routable()} could not see six of the rows at all, because their `realms`
 * column was **NULL**. Those rows had been written past {@see BeamUxEntry::booted()}'s `creating` hook
 * (which defaults `realms` to `[realm]`), and `whereJsonContains` cannot match NULL. So the composer
 * said `/beam/docs/laravel/setup` and the server said 404, for the same row, at the same moment, and
 * **nothing in the estate compared the two** — no test, no doctor audit, no route table, because the
 * renderer is a catch-all and a structural miss is byte-identical to an unknown path.
 *
 * The two classes are **not inverses**, which is the fact this encodes: `UrlResolver` walks `parent_id`
 * and applies the segment grammar, and that is all it does. `EntryPathResolver` additionally filters on
 * realm membership and on `type = page`, and phase 1 resolves a root-absolute segment by a global,
 * tie-broken lookup rather than by the ancestry the composer walked. Every one of those extra
 * conditions is a way for a URL to compose and not serve.
 *
 * ## What it asserts
 *
 * For every **published `page`** entry: compose its URL from its own containment chain, hand that URL
 * back to {@see EntryPathResolver::resolve()} in the entry's own realm, and require the chain that
 * comes back to **end at the same entry**. Two distinguishable failures come out of that one probe —
 * `null` (the URL routes to nothing) and a chain ending elsewhere (the URL routes to a *different*
 * page, the ambiguity `EntryPathResolver::absolute()` documents and tie-breaks).
 *
 * ## Why Warn, and why it counts what it could not read
 *
 * **Warn, never Fail, and advisory on the manifest.** Whether a host publishes entries at all, and
 * which realms it serves, are facts about the host — ecosystem `AGENTS.md`, "a check whose answer
 * depends on the host must not throw".
 *
 * **Inconclusive when the population is empty, and it says the number.** A host with no `beam_ux_entries`
 * table, or with zero published pages, has not been measured clean — it has not been measured. Naming
 * the denominator is what keeps a Pass here from being this estate's signature defect: an instrument
 * that reports success by not running.
 *
 * **A row it could not walk is COUNTED and NAMED, in its own finding.** A dangling `parent_id`, a
 * soft-deleted ancestor or a containment cycle means the chain the composer would walk does not exist —
 * and `UrlResolver` answers anyway, treating the row as top-level. Those rows are excluded from the
 * round trip (there is nothing honest to compare) and reported separately, so "0 unreachable" can never
 * be read as "every row checked out".
 */
class BeamUxReachabilityAudit implements DoctorAudit
{
    private const CHECK = 'entry reachability (composed URL ⇄ EntryPathResolver round trip)';

    private const UNEVALUATED = 'entry reachability (rows whose containment chain could not be walked)';

    public function __construct(
        private UrlResolver $urls,
        private EntryPathResolver $paths,
        private EntryPublishGate $published,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! Schema::hasTable('beam_ux_entries')) {
            return [Finding::inconclusive(
                self::CHECK,
                'beam_ux_entries is absent on the default connection, so there are 0 published `page` '.
                'entries to round-trip. Publish + migrate beam-ux before this can measure anything.',
            )];
        }

        $unreachable = [];
        $mismatched = [];
        $unevaluated = [];
        $considered = 0;

        foreach ($this->candidates() as $entry) {
            $considered++;

            $chain = $this->chain($entry);

            if ($chain === null) {
                $unevaluated[] = $this->label($entry);

                continue;
            }

            $url = $this->urls->resolveChain($chain);
            $realm = $entry->realm ?? BeamUxEntry::REALM_SITE;
            $resolved = $this->paths->resolve($url, $realm);

            if ($resolved === null) {
                $unreachable[] = $this->label($entry)." composes `{$url}` (realm `{$realm}`) and resolves to NOTHING";

                continue;
            }

            $target = $resolved[array_key_last($resolved)];

            if ($target->is($entry)) {
                continue;
            }

            // Measured at www on this audit's first live run, and worth telling apart: eight rows
            // composed `/` because they declare NO segment at all, so the round trip lands on the realm
            // root. Reporting that as "resolves to a different entry" names the wrong cause — the row
            // has no address to be wrong about. Same Warn (it is genuinely unreachable), different
            // sentence, because the repair is "give it a segment", not "resolve an ambiguity".
            if ($url === '/' && ($entry->segment === null || $entry->segment === '')) {
                $unreachable[] = $this->label($entry).' declares NO `segment`, so it composes the realm '.
                    "root's own URL `/` and has no address of its own";

                continue;
            }

            $mismatched[] = $this->label($entry)." composes `{$url}` (realm `{$realm}`) which resolves to a ".
                'DIFFERENT entry, '.$this->label($target);
        }

        return [
            $this->roundTrip($considered, count($unevaluated), $unreachable, $mismatched),
            ...$this->unevaluated($unevaluated, $considered),
        ];
    }

    /**
     * @param  list<string>  $unreachable
     * @param  list<string>  $mismatched
     */
    private function roundTrip(int $considered, int $unevaluated, array $unreachable, array $mismatched): Finding
    {
        $denominator = "{$considered} published `page` entr".($considered === 1 ? 'y' : 'ies').
            ' considered'.($unevaluated > 0 ? ", {$unevaluated} of them unevaluated (see below)" : '');

        if ($considered === 0) {
            return Finding::inconclusive(
                self::CHECK,
                "{$denominator} — this host publishes no routable entries, so the round trip measured ".
                'nothing. That is not the same as measuring it clean.',
            );
        }

        $broken = [...$unreachable, ...$mismatched];

        if ($broken === []) {
            return Finding::pass(
                self::CHECK,
                "{$denominator}; every one resolves back through EntryPathResolver to itself.",
            );
        }

        return Finding::warn(
            self::CHECK,
            "{$denominator}; ".count($broken).' of them do NOT round-trip: '.implode('; ', $broken).
            '. A composed URL is not evidence of reachability — UrlResolver walks `parent_id` only, '.
            'while EntryPathResolver additionally filters `realms` (a NULL `realms` written past '.
            'BeamUxEntry::booted() cannot be matched by whereJsonContains) and `type`, and resolves a '.
            'root-absolute segment globally rather than through the ancestry the URL was composed from. '.
            'Check `realms` first: backfill it from `realm` and re-run.',
        );
    }

    /**
     * @param  list<string>  $unevaluated
     * @return list<Finding>
     */
    private function unevaluated(array $unevaluated, int $considered): array
    {
        if ($unevaluated === []) {
            return [];
        }

        $n = count($unevaluated);

        return [Finding::warn(
            self::UNEVALUATED,
            "{$n} entr".($n === 1 ? 'y' : 'ies')." of {$considered} could not be evaluated — its containment ".
            'chain does not exist (a dangling `parent_id`, a soft-deleted ancestor, or a cycle): '.
            implode(', ', $unevaluated).'. UrlResolver still answers for these rows, composing them as '.
            'though they were top-level, so their URLs are confident and meaningless. They are excluded '.
            'from the round trip above rather than counted clean.',
        )];
    }

    /**
     * Published `page` entries, in a stable order. The publish gate is skipped where the optional
     * `workflow_marking` column is absent — every consumer in this package guards that column, and a
     * host that has not run the workflow migration has no marking to read, not an unpublished estate.
     *
     * @return iterable<BeamUxEntry>
     */
    private function candidates(): iterable
    {
        $gated = Schema::hasColumn('beam_ux_entries', 'workflow_marking');

        return BeamUxEntry::query()
            ->where('type', UxType::Page->value)
            ->orderBy('id')
            ->get()
            ->filter(fn (BeamUxEntry $entry) => ! $gated || $this->published->isPublished($entry));
    }

    /**
     * The entry's root-first containment chain, walked over `parent_id` — or **null** when the chain
     * cannot be walked, which is the distinction {@see UrlResolver::resolve()} cannot make: it reads
     * `$node->parent`, gets null for a dangling id, and composes as if the row were top-level.
     *
     * @return array<int, BeamUxEntry>|null
     */
    private function chain(BeamUxEntry $entry): ?array
    {
        $chain = [$entry];
        $node = $entry;
        $seen = [(string) $entry->getKey() => true];

        while ($node->parent_id !== null) {
            $parent = BeamUxEntry::query()->whereKey($node->parent_id)->first();

            if ($parent === null || isset($seen[(string) $parent->getKey()])) {
                return null;
            }

            $seen[(string) $parent->getKey()] = true;
            array_unshift($chain, $parent);
            $node = $parent;
        }

        return $chain;
    }

    private function label(BeamUxEntry $entry): string
    {
        return '`'.($entry->namespace ?? '~').'/'.$entry->slug.'`';
    }
}
