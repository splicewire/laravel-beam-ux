<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The standing operator check on ADR-0213's chrome columns: an entry naming a `layout` or a `template`
 * that resolves to **neither a registered component name nor another entry's slug**.
 *
 * This is {@see BeamUxAccessAudit}'s sibling and it exists for the same reason — the failure is silent.
 * Chrome resolution is client-side (§7: registered name first, then entry), and an unresolvable name
 * does not 500 or 404: the page renders with the fallback shell, which on a docs site means a guide
 * that quietly loses its rail and its on-this-page column while still returning 200. That is ticket
 * 11's blank-page-behind-a-200 shape, one field over.
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
        $unresolved = [];

        foreach ($entries as $entry) {
            foreach ($axes as $axis) {
                $name = $entry->getAttribute($axis);

                if (! is_string($name) || $name === '') {
                    continue;
                }

                if (in_array($name, $registered, true) || isset($slugs[$name])) {
                    continue;
                }

                $unresolved[] = "{$this->label($entry)} · {$axis}: [{$name}]";
            }
        }

        if ($unresolved !== []) {
            return [Finding::fail($check, 'entries name a layout/template that resolves to neither a '.
                'registered component nor an entry — the page renders in the fallback shell behind a 200, '.
                'so nothing else will ever mention it. Register the name in `beam.ux.chrome.registered`, '.
                'author the entry, or fix the typo: '.implode('; ', $unresolved).'.')];
        }

        return [Finding::pass(
            $check,
            'every declared layout/template resolves to a registered component or an authored entry.',
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
