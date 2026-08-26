<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `GET /beam-ux-entries/{id}/op/body` — an entry's current particle body plus the JSON-Schema that
 * seeds the inspector SchemaForm. The read half of the entry-body transport (ADR-0214 §1), and the
 * server side of every editor open: the SPA UX-builder, the Mainframe-hosted region editor, and the
 * theme editor all round-trip through it.
 *
 * ## Why this is an operation and not a controller
 *
 * It used to be `BeamUxEntryBodyController::show()`, mounted by a bespoke `Route::beamUxEntries()`
 * macro, and it was bespoke for exactly one reason: **it addressed by `slug` where the particle
 * pipeline addresses by `id`**. Every ugly thing in it descended from that — a `?namespace=` query
 * parameter, a null-namespace tiebreak, and a two-query `resolveEntry()`, all of them mitigations for
 * a defect found live (a namespaced `theme` and a null-namespace `page` sharing one slug, where an
 * ambiguous `first()` served the WRONG entry with no error). An id cannot be ambiguous, so ADR-0214 §2
 * made the defect unrepresentable instead of mitigated, and the machinery deleted with it.
 *
 * `BeamUxEntryData` has carried `#[ParticleResource(key: 'beam-ux-entry')]` since it was written, so
 * the resource this hangs off already existed; the operation's `output:` slot is one of
 * `UndeclaredSurfaceAudit`'s legal declaration sites and source 3 in `RouteReturnType`, which is why
 * the surface now declares its shape without a host remembering to write `->beam()->returns()`.
 *
 * ## Two things the mount owes it
 *
 * **It mounts `GET`** (ADR-0214 §4). `Route::particleOp()` defaults to `post` regardless of kind, so
 * a host passes `['method' => 'get']`. The body read is the hot path on every editor open, takes no
 * input, and is idempotent. (`EntryWorkflowShowOp` beside it is also `kind: Read` and mounts POST —
 * that is the older operation being wrong, not a precedent. `Read` ⇒ `GET` as `particleOp`'s DEFAULT
 * is a beam-core doctrine change filed to `particle-contribution-seam`.)
 *
 * **The resource segment stays the single hyphenated `beam-ux-entries`.** A `/` in a resource name
 * breaks Wayfinder's generated-helper relative-import depth calculation, which splits the route *name*
 * on `.` only.
 *
 * ## Authorization
 *
 * `ability: 'ux.author'` with `abilityModel: false`, matching both siblings on this resource
 * (ADR-0214 §3) — the declared ability is the one that travels, because the two live hosts' enclosing
 * middleware groups disagree wildly about what guards this surface and neither writes it down on the
 * surface itself.
 *
 * `abilityModel: false` is not optional here and is not a tightening. `ux.author` is an ENTITLEMENT
 * key, and with the slot left null `ParticleOperationController` hands the loaded entry to
 * `AbilityResolver` and asks a per-action question with a token that is only meaningful subject-free —
 * the shape `particle-operation-surface` ticket 03 warned about, where a declaration that is
 * syntactically complete and semantically on the wrong plane reads at every audit exactly like a
 * correct one. Ticket 08 owns the flip for the whole population and measured it changes no answer; a
 * NEW operation declaring the wrong plane and waiting to be swept would be a fifth specimen filed on
 * purpose.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'body',
    kind: OperationKind::Read,
    model: BeamUxEntry::class,
    ability: 'ux.author',
    abilityModel: false,
    input: false,
    output: BeamUxEntryBodyData::class,
)]
class EntryBodyShowOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        return app(EntryBodyEnvelope::class)->read($model);
    }
}
