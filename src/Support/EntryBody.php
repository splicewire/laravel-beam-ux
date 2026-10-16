<?php

namespace Splicewire\Beam\Ux\Support;

use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * Server-side reader for a beam-ux entry's JsonDoc body (theme-entries-and-authoring STR-03), ported
 * from `rushing/audiostud`'s `App\Beam\EntryBody` (its `forSlug()` docblock already anticipated this
 * generalization — "the auth pages (Fortify views) reuse one reader" — never applied there either).
 *
 * Public/GUEST surfaces (the auth pages: `/login`, `/register`, …) can't client-fetch the auth-gated
 * `beam.ux.entries.body.*` transport (`VisualEditorMount`'s author-mode-only load/save), so their
 * bodies are resolved HERE, server-side, and handed to the page as a prop.
 *
 * **Degrade-not-fabricate.** When the entry ROW is absent (fresh DB / never seeded) — or has no
 * particle body — this returns `null`, and the client's `PageEditor` falls back to its own default
 * tree (`editor/defaults.ts`). An app-critical page (login) thus never blanks because its beam-ux row
 * is missing.
 */
class EntryBody
{
    public function __construct(private StorageDriverResolver $drivers) {}

    /**
     * The `$slug` entry's JsonDoc body under this host's beam-ux namespace, or `null` when the entry
     * row (or its body) is absent — the degrade signal the page reads to fall back to its default tree.
     *
     * @return array<string, mixed>|null
     */
    public function forSlug(string $slug): ?array
    {
        $entry = BeamUxEntry::query()
            ->where('namespace', config('beam.ux.namespace', 'starter'))
            ->where('slug', $slug)
            ->first();

        if ($entry === null || ! is_string($entry->particle_id) || $entry->particle_id === '') {
            return null;
        }

        $item = $this->drivers->resolve($entry)->read($entry->particle_id);

        // No `?->` on $item: PHPStan (this repo's config) flags it as unnecessary — the driver's own
        // return type isn't nullable — audiostud's source used `?->` since its stubs disagree; `?? null`
        // alone still degrades safely if `body` itself is absent.
        return $item->body ?? null;
    }
}
