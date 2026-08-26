<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PolicyWriteGate;

/**
 * `POST /beam-ux-entries/{id}/op/save-body` — persist an edited body to the entry's particle. The
 * write half of the entry-body transport (ADR-0214 §1); {@see EntryBodyShowOp} is the read half and
 * carries the shared rationale for why this pair is two operations rather than a controller.
 *
 * Naming follows the sibling pair on this resource, which names the read for its subject (`workflow`)
 * and the write for its verb (`transition`).
 *
 * ## What one save does, in order — all four steps are load-bearing
 *
 *  1. **Write** through a request-scoped {@see ParticleStorageDriver} over a {@see PolicyWriteGate}-backed
 *     {@see ParticleWriter}. The gate PERMITS when no per-entry policy is declared, deliberately: this
 *     is an authenticated editor write behind the host's auth middleware and this op's declared
 *     `ability`, not a deny-by-default anonymous submission. (ADR-0092 composition seam: the surface
 *     is beam-ux's, the versioned particle it round-trips is beam-core's — this package forks neither.)
 *  2. **Bind the particle id on first write.** An entry can exist with `particle_id` null — `ScaffoldCommand`,
 *     `RegisterEntriesFromDisk` and the tests all create rows that way — and the freshly-minted key is
 *     what makes the next read find anything.
 *  3. **Mirror to disk** at the entry's resolved `FilePlacement` (charter S2 / ADR-0165). The particle
 *     above is the source-of-record; this projects the same body to a git-trackable file at
 *     `{namespace}/{type}/{slug}.{ext}`. A no-op when the storage disk is not configured.
 *  4. **Compile the artifact** (ADR-0209 §7) — the first of the three producers and the one that
 *     matters most, because it is where content actually changes in production. It runs against the
 *     source just persisted, so the artifact and the particle version it is keyed by cannot disagree.
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
    model: BeamUxEntry::class,
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

        $written = self::authoringDriver()->write(
            (string) ($model->particle_id ?? ''),
            $input->body,
            $model->namespace,
        );

        if ($model->particle_id === null && $written->key !== '') {
            $model->particle_id = $written->key;
            $model->save();
        }

        app(PlacedDiskMirror::class)->mirror(
            $model,
            app(PlacementResolver::class)->resolve($model)->pathFor($model),
            $written->body,
        );

        return [
            'key' => $written->key,
            'compileError' => self::compileAfterSave($model),
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

    /** Compile the just-saved body to its artifact, returning the diagnostic instead of throwing. */
    private static function compileAfterSave(BeamUxEntry $entry): ?string
    {
        try {
            app(CompileEntryBody::class)->forEntry($entry->refresh(), force: true);

            return null;
        } catch (CompilationFailed $e) {
            return $e->getMessage();
        }
    }

    /**
     * A request-scoped {@see ParticleStorageDriver} for the authenticated authoring write: the same
     * particle primary the default resolver uses, but over a {@see ParticleWriter} bound to a
     * permissive {@see PolicyWriteGate}, reusing the container-resolved target/acceptance/event
     * dependencies unchanged.
     */
    private static function authoringDriver(): ParticleStorageDriver
    {
        return new ParticleStorageDriver(new ParticleWriter(
            new PolicyWriteGate(app(Gate::class)),
            app(SchemaTargetResolver::class),
            app(AcceptanceGate::class),
            app(Dispatcher::class),
        ));
    }
}
