<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Ux\Canvas\ViewGateFilter;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PolicyWriteGate;

/**
 * The `beam.ux.entries.body.*` endpoint — the server half of the region load/save every beam-ux frontend
 * (the SPA UX-builder AND a Mainframe-hosted region editor) round-trips through, so the inspector edits a
 * REAL {@see BeamUxEntry}'s body, not a fixture.
 *
 * Promoted from the splicewire-app host into beam-ux (beam-native-controllers P4): a body-transport that
 * every host needs belongs to the package. Mount it with `Route::beamUxEntries()` inside the host's
 * auth-guarded group.
 *
 *  - `show`   loads an entry's body through the resolved free-core StorageDriver (particle-primary by
 *             default) and returns the `{ slug, type, schema, body }` editing envelope. Per-node
 *             `data-view-gate` entitlement keys are enforced HERE ({@see ViewGateFilter}) — the ONE
 *             place a body reaches a client, so it's the ONE place that has to strip what an
 *             unentitled viewer must never receive.
 *  - `update` writes an edited body back through the SAME driver — the write lands through beam-core's
 *             shared {@see ParticleWriter}, versioned + migrate-on-read intact. NOT view-gate-filtered:
 *             it echoes back exactly what this request's caller just submitted, not a fresh read.
 *
 * **Host owns the wire and the policy (ADR-0116).** The route is mounted behind the host's auth/tenant
 * middleware, so this is an authenticated editor write, NOT a deny-by-default anonymous submission — it
 * therefore binds a {@see PolicyWriteGate} (permits when no per-entry policy is declared — the route
 * middleware IS the gate) for the write path. Composition seam (ADR-0092): this surface + controller are
 * beam-ux's; the particle body it round-trips through the StorageDriver is beam-core's.
 *
 * **Known limitation (view-gate + concurrent editors).** An editor who can't view a gated subtree never
 * receives it from `show`, so THEIR OWN local copy of the doc is missing it — if they then `update()`,
 * their submitted body genuinely doesn't contain that subtree, and it is lost. There is no merge step
 * reconciling "what this editor never saw" against the previous persisted body; the JsonDoc schema has
 * no stable per-node identity to merge by (paths shift under insert/delete elsewhere in the tree). Not
 * solved here — a real fix needs stable node ids, a larger change than this pass.
 */
class BeamUxEntryBodyController
{
    public function __construct(
        private StorageDriverResolver $drivers,
        private Gate $gate,
        private PlacementResolver $placements,
        private PlacedDiskMirror $mirror,
        private ViewGateFilter $viewGateFilter,
    ) {}

    /** Load an entry's body + schema for seeding the inspector SchemaForm, or 404 when absent. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $entry = $this->resolveEntry($request, $slug);

        $item = $entry->particle_id !== null
            ? $this->drivers->resolve($entry)->read((string) $entry->particle_id)
            : null;

        return response()->json(['data' => new BeamUxEntryBodyData(
            slug: (string) $entry->slug,
            id: (string) $entry->id,
            type: $this->typeValue($entry),
            schema: $this->resolveSchema($entry),
            body: $this->viewGateFilter->filter($item?->body ?? []),
        )]);
    }

    /**
     * Persist an edited body to the entry's particle through the authenticated-authoring
     * {@see PolicyWriteGate}. Returns the freshly-read envelope so the client re-seeds from the durable
     * round-tripped state (proves the save landed).
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $entry = $this->resolveEntry($request, $slug);

        /** @var array<string, mixed> $body */
        $body = (array) $request->input('body', []);

        // The authenticated-authoring write path: a PolicyWriteGate-backed ParticleWriter (the route's
        // auth/tenant middleware already authorized the editor). We build a request-scoped particle driver
        // over that writer so the save is permitted without a per-entry policy, then round-trip.
        $driver = $this->authoringDriver();
        $written = $driver->write((string) ($entry->particle_id ?? ''), $body, $entry->namespace);

        // First-write case: the entry had no particle yet — bind the freshly-minted particle id.
        if ($entry->particle_id === null && $written->key !== '') {
            $entry->particle_id = $written->key;
            $entry->save();
        }

        // Materialize-on-save the disk MIRROR at the entry's FilePlacement path (charter S2 / ADR-0165):
        // the particle above is the source-of-record; this projects the same body to a git-trackable file
        // at `{namespace}/{type}/{slug}.{ext}` under the configured dev root. Particle-primary is preserved
        // (the DB write already landed); the mirror is a no-op when the storage disk is not configured.
        $this->mirror->mirror(
            $entry,
            $this->placements->resolve($entry)->pathFor($entry),
            $written->body,
        );

        // Compile-on-save (ADR-0209 §7): this is the FIRST of the three producers, and the one that
        // matters most — it is where content actually changes in production. The compile runs against
        // the source we just persisted, so the artifact and the particle version it is keyed by cannot
        // disagree.
        $compileError = $this->compileAfterSave($entry);

        $reloaded = $this->drivers->resolve($entry)->read($written->key);

        return response()->json(['data' => new BeamUxEntryBodyData(
            slug: (string) $entry->slug,
            id: (string) $entry->id,
            type: $this->typeValue($entry),
            schema: $this->resolveSchema($entry),
            body: $reloaded?->body ?? $body,
            compileError: $compileError,
        )]);
    }

    /**
     * Compile the just-saved body to its artifact, returning the diagnostic instead of throwing.
     *
     * The save has already landed by the time this runs, and it must stay landed: an author saving
     * half-written MDX is the normal case, and a CMS that refuses the write because the draft does not
     * compile is a worse editor than one that stores it and says so. What §7 forbids is a SILENT
     * degrade — compiling in the reader's browser instead — and nothing here does that: with no
     * artifact the public page 404s and {@see \Splicewire\Beam\Ux\Doctor\BeamUxArtifactAudit} names the
     * entry, while the editor gets the compiler's own message back in the same response.
     *
     * The action is resolved lazily rather than constructor-injected so the body endpoint still mounts
     * on a host that has bound no compiler at all.
     */
    private function compileAfterSave(BeamUxEntry $entry): ?string
    {
        try {
            app(CompileEntryBody::class)->forEntry($entry->refresh(), force: true);

            return null;
        } catch (CompilationFailed $e) {
            return $e->getMessage();
        }
    }

    /**
     * Resolve the entry by slug (the body endpoint's addressing key), 404 when absent. `slug` alone is
     * NOT globally unique — a `namespace`-scoped entry (a theme, a kit component) and a bare page entry
     * can legitimately share one (e.g. both a `theme`-namespaced "beam" and the page-kind "beam" that
     * a host's `/beam` route serves existed side by side, found live: the ambiguous `first()` this used
     * to run picked whichever row's uuid sorted first, silently serving/saving to the WRONG entry with
     * no error).
     *
     * A caller that KNOWS which entry it means (it already has the row — `BeamUxEntryData::$namespace`
     * — from a list/manifest fetch, e.g. the theme editor) passes `?namespace=` explicitly: an EXACT
     * match, empty string meaning "the null-namespace one." Found live: the theme editor has no way to
     * pass this today, so a namespaced `theme` entry sharing a slug with a null-namespace page silently
     * resolved to the WRONG one — this is the fix, not just documentation of the old tiebreak.
     *
     * A caller with no opinion (the in-page canvas editor only ever addresses null-namespace page
     * entries by bare slug) omits `namespace` entirely and gets the OLD tiebreak: a null-namespace
     * entry wins ties; an unambiguous slug (no null-namespace sibling) resolves exactly as before.
     */
    private function resolveEntry(Request $request, string $slug): BeamUxEntry
    {
        if ($request->has('namespace')) {
            $namespace = $request->query('namespace');

            return BeamUxEntry::query()
                ->where('slug', $slug)
                ->where('namespace', $namespace === '' ? null : $namespace)
                ->firstOrFail();
        }

        return BeamUxEntry::query()->where('slug', $slug)->whereNull('namespace')->first()
            ?? BeamUxEntry::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * A request-scoped {@see ParticleStorageDriver} for the authenticated authoring write: the same
     * particle primary the default resolver uses, but over a {@see ParticleWriter} bound to a permissive
     * {@see PolicyWriteGate} (the auth-guarded route is the gate), reusing the container-resolved
     * target/acceptance/event dependencies unchanged.
     */
    private function authoringDriver(): ParticleStorageDriver
    {
        $writer = new ParticleWriter(
            new PolicyWriteGate($this->gate),
            app(SchemaTargetResolver::class),
            app(AcceptanceGate::class),
            app(Dispatcher::class),
        );

        return new ParticleStorageDriver($writer);
    }

    /**
     * The JSON-Schema the SchemaForm renders. `schema_ref` carries EITHER an inline JSON schema (an
     * inferred draft) OR a bare registry stem; we surface the inline object when present. A `theme`
     * entry carries no `schema_ref` (its body is a `{canvas,site}` token object, not a component's
     * inferred props) — it falls back to `ThemeSchemas::canvas()`/`site()`, INLINED by value rather
     * than `$ref`-composed (the two sub-schemas are already flat; a caller's `SchemaForm` renders
     * this with no `schemaFetcher` round trip). `shell` is omitted — no host consuming this today
     * has an `/os` windowed-desktop chrome to theme.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSchema(BeamUxEntry $entry): ?array
    {
        $ref = $entry->schema_ref;
        if (is_string($ref) && $ref !== '') {
            $decoded = json_decode($ref, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($entry->type === UxType::Theme) {
            return [
                'type' => 'object',
                'title' => 'Theme',
                'properties' => [
                    'canvas' => ThemeSchemas::canvas(),
                    'site' => ThemeSchemas::site(),
                ],
            ];
        }

        return null;
    }

    private function typeValue(BeamUxEntry $entry): string
    {
        $type = $entry->getAttribute('type');

        return is_object($type) && property_exists($type, 'value')
            ? (string) $type->value
            : (string) $type;
    }
}
