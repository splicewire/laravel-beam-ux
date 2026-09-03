<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Containment\ChromeEntryResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The standing operator check on ADR-0213's chrome columns: an entry naming a `layout` or a `template`
 * that resolves to **neither a registered component name nor another entry's slug** — and, one step
 * further, a name that IS an entry's slug but one the renderer cannot nest.
 *
 * This is {@see BeamUxAccessAudit}'s sibling and it exists for the same reason — the failure is silent.
 * Chrome resolution is client-side (§7: registered name first, then entry), and an unresolvable name
 * does not 500 or 404: the page renders with the fallback shell, which on a docs site means a guide
 * that quietly loses its rail and its on-this-page column while still returning 200. That is ticket
 * 11's blank-page-behind-a-200 shape, one field over.
 *
 * **"Then entry" means what the renderer can import, not what the table contains.** The first cut
 * accepted any row whose slug matched — deleted rows included — while the page it vouched for consulted
 * only the registry and fell through to no chrome at all (beam-docs-satellite 55: `www` had five rows
 * naming its realm ROOT as their layout, passing this audit, rendering nothing). The renderer now nests
 * an entry's artifact, and this audit accepts a slug on exactly the terms the renderer does
 * ({@see ChromeEntryResolver}): live, a compilable page, with an artifact for its current body. A slug
 * that matches on weaker terms is a **warn**, not a fail — whether that row is compiled, deleted or the
 * wrong type is a fact about this host's state, which the estate's rule keeps advisory.
 *
 * **Why the registered names are config and not introspection.** A layout may be *code shipped by a
 * package* with no row at all (§7), and the registry that holds it is a TypeScript `Record` in the
 * browser bundle. PHP cannot see it, and a check that could only see rows would report "unknown" for
 * exactly the layouts that are working. So the host declares what its bundle registers, under
 * `beam.ux.chrome.registered`, seeded with the names `@splicewire/beam-ux/docs` ships. A host that adds
 * its own layout adds one string; a host that adds none never touches the key.
 *
 * That makes a stale config a source of **false alarms**, which is the right direction for a doctor
 * finding: the alternative — trusting any unresolvable name — is the silent version this exists to end.
 */
class BeamUxChromeAudit implements DoctorAudit
{
    /** The two inherited chrome axes, both plain nullable string columns. */
    private const AXES = ['layout', 'template'];

    public function __construct(private ?ChromeEntryResolver $chromeEntries = null) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $check = 'entry chrome (layout/template)';

        if (! Schema::hasTable('beam_ux_entries')) {
            return [Finding::warn($check, 'beam_ux_entries is absent — publish + migrate beam-ux before auditing entry chrome.')];
        }

        $axes = array_values(array_filter(
            self::AXES,
            fn (string $axis) => Schema::hasColumn('beam_ux_entries', $axis),
        ));

        if ($axes === []) {
            return [Finding::warn(
                $check,
                'neither the `layout` nor the `template` column exists — this host predates ADR-0213; '.
                're-publish and migrate beam-ux for a page to inherit a shell at all.',
            )];
        }

        $entries = BeamUxEntry::query()->withTrashed()->get();

        /** @var array<string, true> $slugs */
        $slugs = [];
        foreach ($entries as $entry) {
            $slugs[(string) $entry->slug] = true;
        }

        $registered = $this->registeredNames();
        $resolver = $this->chromeEntries ?? app(ChromeEntryResolver::class);
        $unresolved = [];
        $unnestable = [];

        foreach ($entries as $entry) {
            foreach ($axes as $axis) {
                $name = $entry->getAttribute($axis);

                if (! is_string($name) || $name === '') {
                    continue;
                }

                if (in_array($name, $registered, true)) {
                    continue;
                }

                if (! isset($slugs[$name])) {
                    $unresolved[] = "{$this->label($entry)} · {$axis}: [{$name}]";

                    continue;
                }

                // A slug match, on the renderer's own terms. `unnestable()` answers for a deleted row too,
                // because `$slugs` was collected with trashed rows in — a deleted layout is the one case
                // where "the table has it" and "the renderer has it" split most quietly.
                $because = $resolver->unnestable($name, $entry);

                if ($because !== null) {
                    $unnestable[] = "{$this->label($entry)} · {$axis}: [{$name}] {$because}";
                }
            }
        }

        $findings = [];

        if ($unresolved !== []) {
            $findings[] = Finding::fail($check, 'entries name a layout/template that resolves to neither a '.
                'registered component nor an entry — the page renders in the fallback shell behind a 200, '.
                'so nothing else will ever mention it. Register the name in `beam.ux.chrome.registered`, '.
                'author the entry, or fix the typo: '.implode('; ', $unresolved).'.');
        }

        if ($unnestable !== []) {
            $findings[] = Finding::warn($check, 'entries name a layout/template that is another entry\'s '.
                'slug, but not one the renderer can nest — the page renders without that chrome behind a '.
                '200. Compile, restore or re-type the named entry, or register the name instead: '.
                implode('; ', $unnestable).'.');
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(
            $check,
            'every declared layout/template resolves to a registered component or a nestable entry.',
        )];
    }

    /**
     * @return list<string>
     */
    private function registeredNames(): array
    {
        $names = config('beam.ux.chrome.registered', []);

        return array_values(array_filter(
            array_map(fn ($name) => is_string($name) ? $name : '', (array) $names),
            fn (string $name) => $name !== '',
        ));
    }

    private function label(BeamUxEntry $entry): string
    {
        return (string) ($entry->title ?? $entry->slug ?? $entry->getKey());
    }
}
