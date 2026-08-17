<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The load/save envelope for a {@see BeamUxEntry}'s **body** — the shape the authoring-surface
 * UX-builder (and any Mainframe-hosted region editor) seeds its inspector SchemaForm from and writes back
 * through. It is the read-model of the `beam.ux.entries.body.*` endpoint: the builder edits a REAL entry's
 * particle body, not a fixture.
 *
 * Promoted from the splicewire-app host into beam-ux (beam-native-controllers P4): the endpoint that
 * round-trips an entry body belongs to the beam-ux package so EVERY host (audiostud, splicewire-app)
 * mounts one shared transport, not an app-local copy.
 *
 * Round-trip contract:
 *  - `show` returns `{ slug, type, schema, body }` — `body` seeds the SchemaForm's `formData`, `schema`
 *    its JSON-Schema; the builder never sees the residency/facade seam (both resolve below the surface).
 *  - `update` accepts `{ body }` and writes it through the free-core StorageDriver / ParticleWriter (the
 *    entry's body rides beam-core's versioned particle — ADR-0092 vendor seam: the surface is paid
 *    `splicewire/*`, the particle body it round-trips is free beam-core).
 *
 * No `#[TypeScript]` here (unlike the app-local original): typed-client codegen is a HOST concern — a host
 * that wants the generated hook re-exposes/subclasses this, so the package never forces a
 * typescript-transformer dependency on every consumer.
 */
class BeamUxEntryBodyData extends Data
{
    /**
     * @param  string  $slug  the entry's authoring-envelope slug (the body endpoint's addressing key)
     * @param  string  $id  the entry's uuid — the `Route::recordVersions()` addressing key
     *                      (`/beam-ux/entries/{id}/versions`); distinct from `slug`, which addresses
     *                      this body endpoint. A client wires the version-history panel off this.
     * @param  string  $type  the UxType (layout|template|page|component|theme) — kind-driven placement input
     * @param  array<string, mixed>|null  $schema  the resolved JSON-Schema for the SchemaForm, or null when
     *                                             the entry declares no inline schema (permissive fallback)
     * @param  array<string, mixed>  $body  the current particle body — seeds the SchemaForm's formData
     */
    public function __construct(
        public string $slug,
        public string $id,
        public string $type,
        public ?array $schema,
        public array $body,
    ) {}
}
