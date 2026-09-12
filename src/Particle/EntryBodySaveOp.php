<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * `POST /beam-ux-entries/{id}/op/save-body` — persist an edited body to the entry's particle. The
 * write half of the entry-body transport (ADR-0214 §1); {@see EntryBodyShowOp} is the read half and
 * carries the shared rationale for why this pair is two operations rather than a controller.
 *
 * Naming follows the sibling pair on this resource, which names the read for its subject (`workflow`)
 * and the write for its verb (`transition`).
 *
 * ## This op is the IMMEDIATE-PUBLISH write, and that is now a statement with a sibling
 *
 * `save-draft` / `publish` ({@see EntryDraftSaveOp}, {@see EntryPublishOp}) split the same act in two
 * for an author who wants to work before anyone reads it. This op stays what it always was — a save
 * that is live the moment it returns — and the difference is only WHEN the publish half runs, not
 * whether. Every step below is {@see EntryPublication}'s, so the two paths cannot drift.
 *
 * ## What one save does, in order — all five steps are load-bearing
 *
 *  1. **Refuse a body this entry's codec cannot store** — 422 on `body`, before anything lands
 *     ({@see EntryPublication::assertStorable()}, which carries the measured reason).
 *  2. **Baseline.** Record the body that is currently LIVE as this entry's first version, because
 *     step 3 is about to overwrite it and nothing else would ever have frozen it.
 *  3. **Write** through a request-scoped particle storage driver over a permissive policy write gate:
 *     this is an authenticated editor write behind the host's auth middleware and this op's declared
 *     `ability`, not a deny-by-default anonymous submission. The **particle id binds on first write**
 *     — an entry can exist with `particle_id` null (`ScaffoldCommand`, `RegisterEntriesFromDisk` and
 *     the tests all create rows that way) and the freshly-minted key is what makes the next read find
 *     anything. (ADR-0092 composition seam: the surface is beam-ux's, the versioned particle it
 *     round-trips is beam-core's — this package forks neither.)
 *  4. **Publish**: record the written body as a version and move the entry's publication pin to it.
 *     The pin is what the artifact's address is keyed on, so this step is what makes the save public.
 *  5. **Mirror to disk** at the entry's resolved `FilePlacement` (charter S2 / ADR-0165) and
 *     **compile the artifact** (ADR-0209 §7) — both follow the PUBLISHED body, both at the address
 *     step 4 named, so the artifact and the version it is keyed by cannot disagree.
 *
 * ## Why a failed compile does not fail the save
 *
 * The write has already landed by step 4 and it must stay landed: an author saving half-written MDX is
 * the normal case, and a CMS that refuses a write because the draft does not compile is a worse editor
 * than one that stores it and says so. What ADR-0209 §7 forbids is a SILENT degrade — compiling in the
 * reader's browser instead — and nothing here does that. With no artifact the public page 404s and
 * {@see \Splicewire\Beam\Ux\Doctor\BeamUxArtifactAudit} names the entry, while the editor gets the
 * compiler's own message back on `compileError` in this same response. Absence is reported in three
 * places and degrades in none, which is what §7 actually asks for.
 *
 * `CompileEntryBody` is resolved lazily rather than injected so the operation still runs on a host
 * that has bound no compiler at all.
 *
 * ## `respond()` does the re-read
 *
 * `handle()` returns the outcome and `respond()` projects the durable round-tripped state, the same
 * split {@see \Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp} uses to re-project a marking. The
 * client re-seeds from what the server actually stored, which is what proves the save landed —
 * echoing back the submitted document would prove nothing.
 *
 * ## Known limitation (view-gate + concurrent editors), carried over unchanged
 *
 * An editor who cannot view a gated subtree never receives it from the read op, so THEIR OWN local
 * copy of the doc is missing it — if they then save, their submitted body genuinely does not contain
 * that subtree, and it is lost. There is no merge step reconciling "what this editor never saw"
 * against the previous persisted body; the JsonDoc schema has no stable per-node identity to merge by
 * (paths shift under insert/delete elsewhere in the tree). Not solved here — a real fix needs stable
 * node ids, a larger change than this pass.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'save-body',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Entitlement plane, subject-free — the same declaration all three siblings on this resource
    // carry (particle-operation-surface ticket 08); {@see EntryBodyShowOp}'s docblock carries the
    // reasoning. Read and write MUST stay on one plane: an editor that can open a document but not
    // save it is worse than either gate alone.
    abilityModel: false,
    input: BeamUxEntryBodyInputData::class,
    output: BeamUxEntryBodyData::class,
)]
class EntryBodySaveOp
{
    /**
     * @return array{key: string, compileError: string|null}
     */
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        $input = BeamUxEntryBodyInputData::validateAndCreate($request->all());

        $publication = app(EntryPublication::class);

        $publication->assertStorable($model, $input->body);

        // The BASELINE goes first, before the write: it records the body that is currently live as this
        // entry's first version, and the write is about to destroy it. Without that, the body a reader
        // was served up to this moment would exist nowhere, and `restore` on the very next edit would
        // have nothing to roll back to. It is a no-op for an entry that already has history.
        $publication->baseline($model);

        $written = $publication->write($model, $input->body);

        return [
            'key' => $written['key'],
            // A save through THIS op is its own publish — that is its contract, and
            // `g2-beam-author-entry` is the journey that proves it. What changed is that it now
            // publishes the way {@see EntryPublishOp} does: the body is recorded as a version, the
            // publication pin moves to it, the disk mirror and the artifact follow the pin. An author
            // who never opens the draft affordance sees no difference, and gains a history.
            'compileError' => $publication->publishWritten($model->refresh()),
        ];
    }

    /**
     * Re-read through the resolved driver and project the envelope, carrying the compile diagnostic
     * across. The re-read is NOT view-gate filtered: it echoes the durable state back to the author who
     * just wrote it, which is the round-trip proof this response exists to give.
     */
    public static function respond(mixed $payload, Model $model): mixed
    {
        /** @var BeamUxEntry $model */
        /** @var array{key: string, compileError: string|null} $payload */
        $reloaded = app(StorageDriverResolver::class)->resolve($model)->read($payload['key']);

        return app(EntryBodyEnvelope::class)->of(
            $model,
            $reloaded?->body ?? [],
            $payload['compileError'],
        );
    }
}
