<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
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
 *             default) and returns the `{ slug, type, schema, body }` editing envelope.
 *  - `update` writes an edited body back through the SAME driver — the write lands through beam-core's
 *             shared {@see ParticleWriter}, versioned + migrate-on-read intact.
 *
 * **Host owns the wire and the policy (ADR-0116).** The route is mounted behind the host's auth/tenant
 * middleware, so this is an authenticated editor write, NOT a deny-by-default anonymous submission — it
 * therefore binds a {@see PolicyWriteGate} (permits when no per-entry policy is declared — the route
 * middleware IS the gate) for the write path. Vendor seam (ADR-0092): this surface + controller = paid
 * `splicewire/*`; the particle body it round-trips through the StorageDriver = free-tier beam-core.
 */
class BeamUxEntryBodyController
{
    public function __construct(
        private StorageDriverResolver $drivers,
        private Gate $gate,
    ) {}

    /** Load an entry's body + schema for seeding the inspector SchemaForm, or 404 when absent. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $entry = $this->resolveEntry($slug);

        $item = $entry->particle_id !== null
            ? $this->drivers->resolve($entry)->read((string) $entry->particle_id)
            : null;

        return response()->json(['data' => new BeamUxEntryBodyData(
            slug: (string) $entry->slug,
            type: $this->typeValue($entry),
            schema: $this->resolveSchema($entry),
            body: $item?->body ?? [],
        )]);
    }

    /**
     * Persist an edited body to the entry's particle through the authenticated-authoring
     * {@see PolicyWriteGate}. Returns the freshly-read envelope so the client re-seeds from the durable
     * round-tripped state (proves the save landed).
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $entry = $this->resolveEntry($slug);

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

        $reloaded = $this->drivers->resolve($entry)->read($written->key);

        return response()->json(['data' => new BeamUxEntryBodyData(
            slug: (string) $entry->slug,
            type: $this->typeValue($entry),
            schema: $this->resolveSchema($entry),
            body: $reloaded?->body ?? $body,
        )]);
    }

    /** Resolve the entry by slug (the body endpoint's addressing key), 404 when absent. */
    private function resolveEntry(string $slug): BeamUxEntry
    {
        return BeamUxEntry::query()->where('slug', $slug)->firstOrFail();
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
     * inferred draft) OR a bare registry stem; we surface the inline object when present, else null.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSchema(BeamUxEntry $entry): ?array
    {
        $ref = $entry->schema_ref;
        if (! is_string($ref) || $ref === '') {
            return null;
        }

        $decoded = json_decode($ref, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function typeValue(BeamUxEntry $entry): string
    {
        $type = $entry->getAttribute('type');

        return is_object($type) && property_exists($type, 'value')
            ? (string) $type->value
            : (string) $type;
    }
}
