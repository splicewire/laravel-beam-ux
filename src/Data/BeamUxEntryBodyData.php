<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
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
 *  - `update` accepts `{ body }` and writes it through the beam-core StorageDriver / ParticleWriter (the
 *    entry's body rides beam-core's versioned particle — ADR-0092 vendor seam: the surface is beam-ux's,
 *    the particle body it round-trips is beam-core's).
 *
 * ## The `#[TypeScript]` omission was wrong on its own terms (ADR-0214 §7)
 *
 * This docblock used to argue that typed-client codegen is a host concern and that the attribute would
 * *"force a typescript-transformer dependency on every consumer."* That was never true of beam-ux,
 * which hard-requires `splicewire/laravel-beam`, which requires `spatie/typescript-transformer`. It was
 * also unique to this package: beam core carries the attribute on 7 Data classes, beam-accounts 12,
 * beam-workflows 13, beam-commerce 24, tower 155 — beam-ux's were the only package Data classes absent
 * from `splicewire-app`'s generated tree, which already resolves types from eight vendor namespaces.
 *
 * The host subclass the omission forced (`App\Data\BeamUxEntryBodyData`, existing solely to carry the
 * attribute) deletes with it, and its own stated rationale was doubly stale — it claimed to surface the
 * type as `App.Data.BeamUxEntryBodyData`, and `surgeon-audit-viability` ticket 34 dropped that remap, so
 * every type now emits at its real PHP namespace. The generated alias name `BeamUxEntryBodyData` is
 * unchanged, so no consumer moves.
 */
#[TypeScript]
class BeamUxEntryBodyData extends BeamData
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
     * @param  string|null  $compileError  why compile-on-save (ADR-0209 §7) could not produce this
     *                                     entry's artifact, or null when it did. The save itself still
     *                                     LANDS — refusing to store a draft with a syntax error in it
     *                                     would be a worse editor than one that tells you — but the
     *                                     page has no artifact until the body compiles, so it 404s
     *                                     publicly and `beam:doctor` names it. Absence is reported in
     *                                     three places and degrades in none, which is what §7's
     *                                     no-silent-fallback rule actually asks for.
     */
    public function __construct(
        public string $slug,
        public string $id,
        public string $type,
        public ?array $schema,
        public array $body,
        public ?string $compileError = null,
    ) {}
}
