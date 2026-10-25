<?php

namespace Splicewire\Beam\Ux\Publish;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Ux\Codec\AcceptsJsonDoc;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Data\EntryVersionData;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PolicyWriteGate;

/**
 * **Draft and published, as two pins into one version history.**
 *
 * Every recorded save of an entry's body mints an immutable {@see Version} of its has-a
 * {@see BeamParticle} through `rushing/laravel-versioning`'s one {@see VersionStore}. Two pointers then
 * say which of those versions each audience is on:
 *
 *  - `beam_particles.head_version` — the WORKING HEAD, the body an author edits. The versioning
 *    package already owns and advances it; nothing here writes it directly.
 *  - `beam_ux_entries.published_version` — the PUBLISHED version, the body a guest reads.
 *    {@see \Splicewire\Beam\Ux\Compile\EntryArtifactStore::version()} keys the compiled artifact's
 *    address on it, so moving this pin IS publishing and leaving it still IS drafting.
 *
 * A draft is pending when the two disagree. That is derived on read ({@see state()}), never stored, so
 * there is no flag a failed write can leave lying about a body that is actually live.
 *
 * ## Why not a second store, a second body column, or the workflow marking
 *
 * A second body column (`draft_payload`) would fork the particle's whole migrate-on-read discipline for
 * the sake of one pointer, and would have to be reconciled with the version history that already holds
 * exactly the same bodies. `workflow_marking` (S6) answers a different question — whether the entry is
 * visible AT ALL — and an entry with a pending draft must stay visible, serving its previous body; a
 * host that binds the publish lifecycle keeps that marking governing the node's visibility and this pin
 * governing which recorded body the visible node serves. The two compose and neither subsumes the other.
 *
 * ## The BASELINE, and why the first draft is not the first version
 *
 * An entry that predates this path has a body and no versions. Recording a draft over it would leave the
 * previously-published body unrecorded — restorable from nothing, and the pin would have nothing to
 * point at. So the first version recorded for an entry is the body that was ALREADY live
 * ({@see baseline()}), minted before the draft is written and pinned as published, and the artifact is
 * recompiled at the address that pin now names. The recompile is not optional: the pin changes the
 * artifact's address, and the file is at the old one.
 *
 * Explicit publication compiles the named snapshot before changing the pin or disk mirror
 * (ADR-0232 d7). Drafts compile at their own addresses and return compileError without losing
 * the saved body. System seed/registration keeps its existing pin-and-diagnostic contract.
 */
class EntryPublication
{
    public function __construct(
        private VersionStore $versions,
        private StorageDriverResolver $drivers,
        private PlacedDiskMirror $mirror,
        private PlacementResolver $placements,
    ) {}

    /**
     * The entry's current publication state — both pins, the derived draft flag, and the history.
     *
     * An entry with no particle has no history and no pins: nothing has ever been written, so there is
     * nothing to have drafted or published.
     */
    public function state(BeamUxEntry $entry, ?string $compileError = null): EntryPublicationData
    {
        $particle = $this->recordsVersions() ? $this->particle($entry) : null;

        if ($particle === null) {
            return new EntryPublicationData(
                id: (string) $entry->getKey(),
                draftPending: false,
                publishedVersion: null,
                publishedReadable: null,
                headVersion: null,
                headReadable: null,
                versions: [],
                compileError: $compileError,
            );
        }

        $head = $this->versions->head($particle);
        $published = $this->publishedVersionId($entry);

        $rows = $this->versions->versions($particle);

        $publishedRow = $rows->first(static fn (Version $v): bool => (string) $v->id === (string) $published);

        return new EntryPublicationData(
            id: (string) $entry->getKey(),
            draftPending: $head !== null && (string) $head->id !== (string) $published,
            publishedVersion: $published,
            publishedReadable: $publishedRow?->readableVersion(),
            headVersion: $head === null ? null : (string) $head->id,
            headReadable: $head?->readableVersion(),
            versions: $rows->map(fn (Version $version): EntryVersionData => EntryVersionData::fromVersion(
                $version,
                isHead: $head !== null && (string) $version->id === (string) $head->id,
                isPublished: $published !== null && (string) $version->id === (string) $published,
            ))->values()->all(),
            compileError: $compileError,
        );
    }

    /**
     * Write a body to the entry's working copy and RECORD it as a version — without publishing it.
     *
     * The draft compiles at its own address (ADR-0232 d7). No disk mirror or public pin moves;
     * compilation errors are diagnostics on a retained draft, never a loss of the author's save.
     *
     * @param  array<string, mixed>  $body
     */
    /** Editor CAS: a stale base never reaches the write path (ADR-0225 d6). */
    public function draftFromBase(BeamUxEntry $entry, array $body, ?string $baseVersion, ?string $label = null, ?Model $actor = null, ?\Splicewire\Beam\Ux\Data\EntryPageSettingsData $pageSettings = null): EntryPublicationData
    {
        $this->requireVersionStore();

        return $entry->getConnection()->transaction(function () use ($entry, $body, $baseVersion, $label, $actor, $pageSettings) {
            if ($pageSettings?->recordPage !== null) {
                app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->lockForSettings($entry, $pageSettings);
            }
            $locked = $entry->newQuery()->lockForUpdate()->findOrFail($entry->getKey());
            if ($pageSettings !== null) {
                app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->assertSettings($locked, $pageSettings);
            }
            $before = $this->state($locked);
            if ($before->headVersion !== $baseVersion) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('The editor base version has moved. Reload or explicitly save your changes against the new base.');
            }

            $unauthored = $before->headVersion === null && $before->versions === []
                && $this->publishedVersionId($locked) === null
                && $this->particle($locked)?->getAttribute('head_version') === null;
            $unpublishedComponent = app(\Splicewire\Beam\Ux\Components\AuthoredComponents::class)->authored($locked)
                && $before->publishedVersion === null;
            if (! $unauthored && ($before->headVersion === null || (! $unpublishedComponent && ($before->publishedVersion === null
                || ! array_filter($before->versions, fn ($version) => $version->id === $before->publishedVersion))))) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('This entry has history but no valid Publication Pin. Repair its pin before editing.');
            }
            if ($unauthored) {
                // FIRST SAVE, never mount: the locked null-base comparison owns initialization.
                // The baseline is server-owned. A client-supplied initialBody is not authority.
                $baseline = app(EntryInitialBody::class)->resolve($locked);
                if ($locked->particle_id === null) {
                    $this->write($locked, $baseline);
                }
                $version = $this->versions->snapshot($this->requireParticle($locked), 'baseline');
                // The writer may normalize legacy styles: compile and mirror the frozen snapshot.
                $baseline = $version->snapshot['payload'];
                app(CompileEntryBody::class)->forVersion($locked, (string) $version->id, $baseline);
                // A new component has no guest-visible default page to preserve. Its first draft is not publication.
                if (! app(\Splicewire\Beam\Ux\Components\AuthoredComponents::class)->authored($locked)) {
                    $locked->published_version = (string) $version->id;
                    $locked->save();
                    $snapshot = clone $locked;
                    $locked->getConnection()->afterCommit(function () use ($snapshot, $baseline) {
                        $this->mirror->mirror($snapshot, $this->placements->resolve($snapshot)->pathFor($snapshot), $baseline);
                    });
                }
            }

            $state = $this->recordDraftAfterLocks($locked, $body, $label, $pageSettings);
            $this->audit($locked, 'drafted', $actor, $state);

            return $state;
        });
    }

    /** Publish precisely the version the author saw; do not overwrite a newer working copy. */
    public function publishVersion(BeamUxEntry $entry, string $ref, ?Model $actor = null): EntryPublicationData
    {
        return $this->publishVersions($entry, [(string) $entry->getKey() => $ref], $actor)[(string) $entry->getKey()];
    }

    /**
     * Publish fixed member versions on one entry connection. Authorization belongs to the caller.
     * The Release participant calls this inside its workflow transaction; single publish shares it.
     *
     * @param  array<string, string>  $refs  Entry id => fixed version id.
     * @param  array<string, ?string>|null  $expectedPins  Complete observed-pin map, when publishing a Release.
     * @return array<string, EntryPublicationData>
     */
    public function publishVersions(BeamUxEntry $context, array $refs, ?Model $actor = null, ?array $expectedPins = null, ?callable $completion = null): array
    {
        $this->requireVersionStore();

        return $context->getConnection()->transaction(function () use ($context, $refs, $actor, $expectedPins, $completion) {
            // SQLite ignores FOR UPDATE; reserve its writer before reading pins. No model events or timestamps.
            if ($context->getConnection()->getDriverName() === 'sqlite') {
                $context->newQuery()->whereKey($context->getKey())->toBase()->update(['id' => $context->getKey()]);
            }
            // Match the URL validator's global lock order, including entries outside this Release.
            $entries = $context->newQuery()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $prepared = [];
            $changes = [];
            $before = [];
            foreach ($refs as $id => $ref) {
                $entry = $entries->get($id);
                if ($entry === null) {
                    throw ValidationException::withMessages(['release' => 'A Release entry no longer exists.']);
                }
                if ($expectedPins !== null && (! array_key_exists($id, $expectedPins) || $this->publishedVersionId($entry) !== $expectedPins[$id])) {
                    throw ValidationException::withMessages(['release' => 'A publication pin moved. Rebase or drop that Release member before publishing.']);
                }
                $particle = $this->particle($entry);
                $version = $particle === null ? null : $this->versions->version($particle, $ref);
                if ($version === null || ! is_array($version->snapshot['payload'] ?? null)) {
                    throw ValidationException::withMessages(['ref' => 'The named version does not belong to this entry or has no valid body snapshot.']);
                }
                $body = $version->snapshot['payload'];
                $pin = $this->publishedVersionId($entry);
                $publishedBody = $pin === null ? null : ($this->versions->version($particle, $pin)?->snapshot['payload'] ?? null);
                app(CanvasBodyGuard::class)->assertPublishable(app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->validationEntry($entry, $version->snapshot['meta']['pageSettings'] ?? null, false), $body, JsonDocShape::is($publishedBody) ? $publishedBody : [], $refs);
                $before[$id] = $this->mirroredFile($entry, is_array($publishedBody) ? $publishedBody : null);
                $settings = $version->snapshot['meta']['pageSettings'] ?? null;
                if ($settings !== null) {
                    $data = \Splicewire\Beam\Ux\Data\EntryPageSettingsData::validateAndCreate($settings);
                    $changes[$id] = ['parentId' => $data->parentId, 'segment' => $data->segment];
                }
                $prepared[$id] = [$entry, $version, $body];
            }
            app(EntryUrlChanges::class)->assertAllowed($context, $changes);
            // Compile every fixed snapshot before any member's marking, fields or pin changes.
            foreach ($prepared as [$entry, $version, $body]) {
                $this->applyPageSettings($entry, $version, validateUrl: false);
                app(CompileEntryBody::class)->forVersion($entry, (string) $version->id, $body);
            }
            $states = [];
            $workflow = app(\Splicewire\Beam\Workflows\Control\WorkflowActuator::class);
            foreach ($prepared as $id => [$entry, $version, $body]) {
                if ($workflow->manages($entry) && $entry->workflow_marking !== BeamUxEntry::MARKING_PUBLISHED) {
                    $result = $workflow->transition($entry, 'publish', new \Splicewire\Beam\Workflows\Control\TransitionContext(
                        actor: $actor === null ? null : 'user:'.$actor->getKey(),
                    ));
                    if (! $result->applied) {
                        throw ValidationException::withMessages(['publish' => 'The entry lifecycle refused the publish transition.']);
                    }
                    $entry->refresh();
                    $this->applyPageSettings($entry, $version, validateUrl: false);
                }
                $entry->published_version = (string) $version->id;
                $entry->save();
                $states[$id] = $this->state($entry);
                $this->audit($entry, 'published', $actor, $states[$id]);
                // ADR-0233 d5: a File Language change moves the mirror file; the old path rides with the member as
                // its named `moved` slot ({@see mirroredFile()}), beside the [entry, version, body] the recording reads.
                $moved = $this->moved($before[$id] ?? null, $entry);
                $prepared[$id]['moved'] = $moved;
                // A later member may still refuse: no disk mirror escapes a rolled-back Release.
                $snapshot = clone $entry;
                if ($completion === null) {
                    $entry->getConnection()->afterCommit(function () use ($snapshot, $body, $moved) {
                        $this->mirror->mirror($snapshot, $this->placements->resolve($snapshot)->pathFor($snapshot), $body);
                        if ($moved !== null) {
                            $this->mirror->removeIfUnchanged($moved['path'], $moved['bytes']);
                        }
                        app(CompileEntryBody::class)->artifacts()->prune($snapshot->fresh());
                    });
                }
            }
            if ($completion !== null) {
                $completion($prepared);
            }

            return $states;
        });
    }

    public function recordDraft(BeamUxEntry $entry, array $body, ?string $label = null, ?\Splicewire\Beam\Ux\Data\EntryPageSettingsData $pageSettings = null): EntryPublicationData
    {
        if ($pageSettings?->recordPage !== null) {
            return $entry->getConnection()->transaction(function () use ($entry, $body, $label, $pageSettings) {
                app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->lockForSettings($entry, $pageSettings);

                return $this->recordDraftAfterLocks($entry, $body, $label, $pageSettings);
            });
        }

        return $this->recordDraftAfterLocks($entry, $body, $label, $pageSettings);
    }

    private function recordDraftAfterLocks(BeamUxEntry $entry, array $body, ?string $label, ?\Splicewire\Beam\Ux\Data\EntryPageSettingsData $pageSettings): EntryPublicationData
    {
        $this->assertStorable($entry, $body);
        if ($pageSettings !== null && $entry->type !== \Splicewire\Beam\Ux\Type\UxType::Page) {
            throw ValidationException::withMessages(['pageSettings' => 'Only pages have page settings.']);
        }
        if ($pageSettings !== null) {
            app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->assertSettings($entry, $pageSettings);
            // ADR-0233 d4: a page names only Page Fields the host registers, its own are checked against the class
            // the parent it names declares (a draft that moves the page answers to its new parent), and an unclaimed
            // key may name nothing declared (d3).
            if ($pageSettings->fileLanguage !== null && ! \Splicewire\Beam\Ux\Format\FileLanguage::allows($entry, $pageSettings->fileLanguage)) {
                throw ValidationException::withMessages(['pageSettings.fileLanguage' => "This page cannot be written in {$pageSettings->fileLanguage} on this host (ADR-0233 d5)."]);
            }
            if ($pageSettings->childFields !== null && \Splicewire\Beam\Ux\Containment\PageFields::classNamed($pageSettings->childFields) === null) {
                throw ValidationException::withMessages(['pageSettings.childFields' => 'The host registers no Page Fields by this name (beam.ux.page_fields).']);
            }
            $class = \Splicewire\Beam\Ux\Containment\PageFields::forParent($entry, $pageSettings->parentId);
            $pageSettings->fields = \Splicewire\Beam\Ux\Containment\PageFields::check($class, $pageSettings->fields);
            \Splicewire\Beam\Ux\Containment\PageFields::assertUnclaimed($class, $pageSettings->unclaimed);
        }
        if ($pageSettings !== null && \Splicewire\Beam\Ux\Files\MdxPageFile::applies($entry)) {
            \Splicewire\Beam\Ux\Files\MdxPageFile::assertPrintable($pageSettings);
        }
        $this->requireVersionStore();
        // An author-requested draft still needs the current public body mirrored as its baseline.
        $this->baseline($entry, mirror: true);
        $priorSettings = $this->particle($entry)?->meta['pageSettings'] ?? null;
        $this->write($entry, $body, $pageSettings);
        if ($pageSettings !== null || $priorSettings !== null) {
            $particle = $this->requireParticle($entry->refresh());
            $meta = $particle->meta ?? [];
            $meta['pageSettings'] = $pageSettings?->toArray() ?? $priorSettings;
            $particle->meta = $meta;
            $particle->save();
        }
        $version = $this->versions->snapshot($this->requireParticle($entry), $label ?? 'draft');

        $state = $this->state($entry->refresh(), $this->compileDraft($entry, $version));
        $components = app(\Splicewire\Beam\Ux\Components\AuthoredComponents::class);
        if ($components->authored($entry)) {
            $state->diagnostics = $components->strandedInstances($entry, app(\Splicewire\Beam\Ux\Manifest\ManifestProfileResolver::class)->resolve($entry));
        }

        return $state;
    }

    /**
     * Publish the entry's working copy: pin it, mirror it, compile it.
     *
     * A missing artifact must compile before the publication pin moves (ADR-0232 d7).
     */
    public function publish(BeamUxEntry $entry, ?string $label = null): EntryPublicationData
    {
        return $entry->getConnection()->transaction(function () use ($entry, $label) {
            $entry->newQuery()->orderBy('id')->lockForUpdate()->get();

            return $this->publishLocked($entry->fresh(), $label);
        });
    }

    private function publishLocked(BeamUxEntry $entry, ?string $label): EntryPublicationData
    {
        if ($entry->particle_id === null) {
            // Nothing has ever been written to this entry, so there is no body to publish. Reporting the
            // empty state is the honest answer; minting an empty version would manufacture history.
            return $this->state($entry);
        }

        // ADR-0225 decision 2: the pin is required wherever the editor is mounted. The pin-less branch
        // that mirrored and compiled at the unpinned address is deleted; a host without the version
        // store refuses here, naming the fix, instead of publishing outside the entry's one history.
        $this->requireVersionStore();

        $particle = $this->particle($entry);

        if ($particle === null) {
            return $this->state($entry);
        }

        // Publishing serves the body, so it refuses code and illegality alike (ADR-0227 d1, ADR-0228 d8):
        // a restore may have stored an illegal draft (d9 as amended), and it must not go live from here.
        // BEFORE baseline(): on an entry with no history baseline() itself pins, mirrors and compiles the
        // working copy, and would then also be the version the legality diff compares against (review-r1 R2).
        $guard = app(CanvasBodyGuard::class);
        $pinned = $this->publishedVersionId($entry);
        $publishedBody = $pinned === null ? null : ($this->versions->version($particle, $pinned)?->snapshot['payload'] ?? null);
        $guard->assertPublishable(app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->validationEntry($entry), $guard->workingHead($entry), JsonDocShape::is($publishedBody) ? $publishedBody : []);

        $this->baseline($entry, mirror: true);
        $particle = $this->particle($entry->refresh()) ?? $particle;

        $version = $this->versionForWorkingCopy($particle, $label ?? 'published');

        return $this->state($entry->refresh(), $this->pinAndCompile($entry, $version, requireCompiled: true));
    }

    /**
     * Roll the entry FORWARD to a recorded version, as a DRAFT (ADR-0225 decision 5).
     *
     * Forward, never back: the store applies the frozen snapshot onto the working copy and moves HEAD to
     * it, and a fresh version of the result is then recorded, so history is appended to rather than
     * rewound — `rushing/laravel-versioning`'s own documented restore shape. It does NOT publish: the
     * Publication Pin, the disk mirror and the compiled artifact stay on the published version, so a guest
     * keeps reading what they read before and `draftPending` is true. Rolling back the live page takes
     * Restore and then {@see publish()}, which is the rule that only Publish changes what a guest sees.
     *
     * @throws ValidationException when no such version belongs to this entry
     */
    public function restore(BeamUxEntry $entry, string $ref, ?string $label = null, ?Model $actor = null): EntryPublicationData
    {
        $this->requireVersionStore();

        return $entry->getConnection()->transaction(function () use ($entry, $ref, $label, $actor) {
            $entry = $entry->newQuery()->lockForUpdate()->findOrFail($entry->getKey());
            $particle = $this->particle($entry);

            $version = $particle === null ? null : $this->versions->version($particle, $ref);

            if ($particle === null || $version === null) {
                throw ValidationException::withMessages([
                    'ref' => "No version [{$ref}] belongs to this entry; there is nothing to restore.",
                ]);
            }

            // ADR-0227 d1 — a restore must not bring code into a tenant entry: guard the version's body BEFORE
            // applying it. ADR-0228 d9: legality is diagnostic on restore, not a refusal.
            $restoredBody = $version->snapshot['payload'] ?? null;
            $diagnostics = app(CanvasBodyGuard::class)->admitForRestore($entry, is_array($restoredBody) ? $restoredBody : []);

            $this->versions->restore($particle, (string) $version->id);

            $draft = $this->versions->snapshot($this->requireParticle($entry->refresh()), $label ?? "restore of {$version->readableVersion()}");

            $state = $this->state($entry->refresh(), $this->compileDraft($entry, $draft));
            $state->diagnostics = $diagnostics;
            $this->audit($entry, 'restored', $actor, $state);

            return $state;
        });
    }

    /**
     * ADR-0225 decisions 3 and 8: Clear records a draft on the same particle history.
     * The binding, publication pin, mirror and public artifact remain intact. History restores
     * the former body through VersionStore; activity is audit only, never a restoration source.
     */
    public function clear(BeamUxEntry $entry, ?string $label = null, ?Model $actor = null): EntryPublicationData
    {
        if ($entry->particle_id === null) {
            return $this->state($entry);
        }

        $this->requireVersionStore();

        return $entry->getConnection()->transaction(function () use ($entry, $label, $actor): EntryPublicationData {
            $locked = $entry->newQuery()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->state($locked);
            $state = $this->recordDraft($locked, [], $label ?? 'cleared');
            $this->audit($locked, 'cleared', $actor, $state, ['previousHeadVersion' => $before->headVersion]);

            return $state;
        });
    }

    /** ADR-0225 d8: audit metadata only, on the entry's realm connection. */
    private function audit(BeamUxEntry $entry, string $event, ?Model $actor, EntryPublicationData $state, array $extra = []): void
    {
        $logger = activity('beam-entry')->performedOn($entry)->event($event)
            ->withProperties($extra + ['headVersion' => $state->headVersion, 'publishedVersion' => $state->publishedVersion])
            ->tap(fn ($activity) => $activity->setConnection($entry->getConnectionName()));
        if ($actor === null) {
            $logger->causedByAnonymous();
        } else {
            $logger->causedBy($actor);
        }
        $logger->log('Entry '.$event);
    }

    /**
     * Publish a body the caller has ALREADY written — the immediate-publish path
     * {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} takes, where a save is its own publish.
     *
     * Separate from {@see publish()} only in that it skips the baseline: the caller has already written
     * over the working copy, so the body a baseline would freeze is gone. The save op takes the baseline
     * itself, before its write, for exactly that reason.
     *
     * `$mirror = false` is for a system write: register-from-disk and its hydration, a seed and a re-seed. A body read
     * from its placement file would be rewritten into canonical form, and a seeded one would land files nobody authored
     * in the host's tree (launch row qitem-20261020153346-104d5668). An author's save still mirrors (ADR-0165).
     */
    public function publishWritten(BeamUxEntry $entry, ?string $label = null, bool $mirror = true): ?string
    {
        return $this->publishWorkingCopy($entry, $label, false, $mirror);
    }

    /** Programmatic author save: retain its written draft, but never publish a failed compile. */
    public function publishAuthoredBody(BeamUxEntry $entry, ?string $label = null): ?string
    {
        return $this->publishWorkingCopy($entry, $label, true);
    }

    private function publishWorkingCopy(BeamUxEntry $entry, ?string $label, bool $requireCompiled, bool $mirror = true): ?string
    {
        if ($entry->particle_id === null) {
            return null;
        }

        // ADR-0225 decision 2: no pin-less publish. See {@see publish()}.
        $this->requireVersionStore();

        $particle = $this->particle($entry);

        if ($particle === null) {
            return null;
        }

        return $this->pinAndCompile($entry, $this->versionForWorkingCopy($particle, $label ?? 'published'), $requireCompiled, $mirror);
    }

    /**
     * Record the CURRENT published body as the entry's first version, before anything overwrites it.
     *
     * A no-op for an entry that already has history, or one that has never been written at all. For the
     * rest — every entry authored before this path existed — it is what makes the previously-live body
     * restorable and gives the publication pin something true to point at. The artifact is recompiled
     * because pinning moves its address; the body being compiled is the one already on the page, so a
     * reader sees nothing change.
     *
     * System repairs default to no mirror: pinning legacy state must not write into a host's source tree. Author
     * save paths that need to preserve the currently served body pass `mirror: true` explicitly.
     */
    public function baseline(BeamUxEntry $entry, bool $mirror = false): void
    {
        $particle = $this->recordsVersions() ? $this->particle($entry) : null;

        if ($particle === null || $this->publishedVersionId($entry) !== null) {
            return;
        }

        if ($this->versions->versions($particle)->isNotEmpty()) {
            return;
        }

        // ONE transaction for the snapshot and the pin (review-r1, 2026-10-16). The snapshot commits a version and moves
        // HEAD; if the pin save (or the mirror) then failed on its own, the row was left history-bearing and pin-less,
        // which every later baseline() reads as an unpublished draft and skips, so it could never be repaired. Rolled
        // back together, a failure leaves the row exactly as it was and a retry pins it.
        $entry->getConnection()->transaction(
            fn () => $this->pinAndCompile($entry, $this->versions->snapshot($particle, 'baseline'), false, $mirror),
        );
    }

    /**
     * Refuse a **canvas document written onto a format that cannot carry one** — 422 on `body`, before
     * anything lands. The same refusal on the same field for every write path, because they are the
     * same write; it lived on {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} when that op was the
     * only one.
     *
     * This is the one shape check a declared input cannot make. The write DTOs validate the payload
     * against themselves (`present`, `array`), and by that standard a `JsonNode[]` list is a perfectly
     * good body; what makes it wrong is the ENTRY it is aimed at, which those DTOs deliberately do not
     * carry (ADR-0214 §2 — the entry is addressed by `{id}` on the route). So the check lives at the
     * first point that holds both halves, and reuses the input's own 422 envelope rather than inventing
     * a second refusal shape for a client to learn.
     *
     * Measured, 2026-09-11 (G2-BEAM-AUTHOR-ENTRY): the dock offered the canvas on the mdx `/docs` entry;
     * Save wrote its JsonDoc over the `{frontmatter,content}` particle payload; `MdxBodyCodec::decode()`
     * found neither key, so the disk mirror wrote 0 bytes and the compile produced an empty artifact,
     * and `/docs` went blank for every visitor. Nothing downstream can recover from that — the mirror
     * and the compiler are both faithfully projecting a source-of-record that has already lost the
     * source. A DRAFT path that skipped the guard would be strictly worse than no guard: the damage
     * would land at draft time and first become visible to whoever pressed Publish.
     *
     * The question asked is a codec CAPABILITY ({@see AcceptsJsonDoc}), never a format name: a host's
     * own `UxFormatCase` earns the canvas by implementing the marker, and beam-ux never learns its name.
     *
     * @param  array<string, mixed>  $body
     */
    public function assertStorable(BeamUxEntry $entry, array $body): void
    {
        if (! JsonDocShape::is($body) || $entry->codec() instanceof AcceptsJsonDoc) {
            return;
        }

        $format = $entry->getAttribute('format');
        $format = is_object($format) && property_exists($format, 'value') ? (string) $format->value : (string) $format;

        throw ValidationException::withMessages([
            'body' => sprintf(
                'This entry is authored as %s source, which cannot carry a canvas document. Saving one '.
                'here would replace the source with an empty file. Edit it as %s source, or change the '.
                "entry's format first.",
                $format,
                $format,
            ),
        ]);
    }

    /**
     * Write a body to the entry's particle, binding a first-write `particle_id`.
     *
     * A request-scoped {@see ParticleStorageDriver} over a permissive {@see PolicyWriteGate}, identical
     * to the one the save op has always built — this is an authenticated editor write behind the host's
     * auth middleware and the operation's declared `ability`, not an anonymous submission.
     *
     * @param  array<string, mixed>  $body
     * @return array{key: string, body: array<string, mixed>}
     */
    public function write(BeamUxEntry $entry, array $body, ?\Splicewire\Beam\Ux\Data\EntryPageSettingsData $pageSettings = null): array
    {
        $body = app(\Splicewire\Beam\Ux\Components\AuthoredComponents::class)->prepare($entry, $body);
        // ADR-0227 d1 / ADR-0228 d8 — THE CHOKE: every canvas-body write that goes through publication
        // (save-body, save-source, a draft via recordDraft) is held to the realm's Manifest Profile here,
        // including its code boundary, so no op can bypass it by forgetting to call the guard. A non-canvas
        // body is source: refused where the profile refuses code, except a clear and a Form-kind token object.
        app(CanvasBodyGuard::class)->assertAdmitted(app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->validationEntry($entry, $pageSettings?->toArray()), $body);
        try {
            if (JsonDocShape::is($body)) {
                $body = \Splicewire\Beam\Ux\Style\NormalizeBlockStyles::apply($body,
                    \Splicewire\Beam\Ux\Style\StyleRegistry::fromTheme(app(\Splicewire\Beam\Ux\Theme\ThemeResolver::class)->resolve()));
            } elseif ($entry->type === \Splicewire\Beam\Ux\Type\UxType::Theme) {
                \Splicewire\Beam\Ux\Codec\CssBodyCodec::responsiveCss($body);
            }
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        }

        $written = $this->authoringDriver()->write(
            (string) ($entry->particle_id ?? ''),
            $body,
            $entry->namespace,
        );

        if ($entry->particle_id === null && $written->key !== '') {
            $entry->particle_id = $written->key;
            $entry->save();
        }

        return ['key' => $written->key, 'body' => $written->body];
    }

    /**
     * Can this host record versions and hold a publication pin at all?
     *
     * Both halves are OPTIONAL schema this package has always guarded rather than assumed — the same
     * `Schema::hasColumn` tolerance {@see BeamUxEntry::publishedMarkingAttributes()} gives the workflow
     * marking. The version table is beam-CORE's (`beam_versions`, published under `beam-migrations`) and
     * the pin is this package's (`beam-ux-migrations`), and a host can legitimately be mid-way through
     * either.
     *
     * **Where this returns false, nothing publishes (ADR-0225 decision 2: no pin-less publish).** Every
     * door that writes the entry's history ({@see recordDraft()}, {@see publish()}, {@see publishWritten()},
     * {@see restore()}, and the save op that ends in `publishWritten()`) calls {@see requireVersionStore()}
     * and refuses out loud with the remedy, BEFORE it writes. There is no unpinned fallback: a host
     * mid-way through either migration fails closed until it has migrated, and
     * {@see \Splicewire\Beam\Ux\Doctor\BeamUxVersionStoreAudit} names it before an author finds out.
     * The baseline and clearing an entry TOLERATE a false here: they do write (a baseline version and pin, a
     * cleared entry) when the store exists, and without it they skip the snapshot and pin rather than refuse,
     * and neither publishes through an unpinned fallback.
     */
    public function recordsVersions(): bool
    {
        return Schema::hasTable((new Version)->getTable())
            && Schema::hasColumn('beam_ux_entries', 'published_version');
    }

    /**
     * Refuse a draft/restore on a host that cannot record one, naming both halves and the remedy.
     *
     * A publish-gate claim this package cannot verify is exactly the shape that reads green while the
     * table is absent, so the refusal states what it checked rather than "versioning unavailable".
     */
    public function requireVersionStore(): void
    {
        if ($this->recordsVersions()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Entry draft/publish needs the version store and the publication pin, and this host has %s. '
            .'Publish and run the migrations: `vendor:publish --tag=beam-migrations --tag=beam-ux-migrations` '
            .'then `migrate`.',
            implode(' and ', array_filter([
                Schema::hasTable((new Version)->getTable()) ? null : "no [{$this->versionTable()}] table",
                Schema::hasColumn('beam_ux_entries', 'published_version') ? null : 'no [beam_ux_entries.published_version] column',
            ])),
        ));
    }

    private function versionTable(): string
    {
        return (new Version)->getTable();
    }

    /** The id the entry's publication pin holds, or null on a host whose column predates it. */
    public function publishedVersionId(BeamUxEntry $entry): ?string
    {
        $pin = $entry->getAttribute('published_version');

        return $pin === null || $pin === '' ? null : (string) $pin;
    }

    /**
     * The version to publish: HEAD when HEAD already froze exactly the working copy (a publish straight
     * after a draft pins that draft rather than minting a twin of it), otherwise a fresh snapshot.
     *
     * The comparison is against {@see BeamParticle::toVersionSnapshot()} — the record's own statement of
     * what a version freezes — so a writer that bypasses this service (a disk import, a console
     * backfill) is correctly seen as having moved the working copy, and its body is recorded before it
     * is published.
     */
    private function versionForWorkingCopy(BeamParticle $particle, string $label): Version
    {
        $head = $this->versions->head($particle);

        if ($head !== null && $head->snapshot == $particle->toVersionSnapshot()) {
            return $head;
        }

        return $this->versions->snapshot($particle, $label);
    }

    /**
     * Move the publication pin to `$version`, mirror the body to disk, and compile the artifact at the
     * address the pin now names. Returns the compile diagnostic, or null.
     *
     * Explicit author publication compiles at the snapshot address first. System publication
     * retains its established pin-first diagnostic behavior pending its separately nominated policy.
     */
    private function pinAndCompile(BeamUxEntry $entry, Version $version, bool $requireCompiled = false, bool $mirror = true): ?string
    {
        $body = $version->snapshot['payload'] ?? null;
        if (! is_array($body)) {
            throw ValidationException::withMessages(['ref' => 'The named version has no valid body snapshot.']);
        }
        // System registration/seed retains its established pin + diagnostic contract. EP-10
        // changes explicit publication, not boot-time availability of shipped entries.
        if (! $requireCompiled) {
            $entry->setAttribute('published_version', (string) $version->id);
            $entry->save();

            return $this->mirrorAndCompile($entry, $mirror);
        }
        $particle = $this->requireParticle($entry);
        $pinned = $this->publishedVersionId($entry);
        $publishedBody = $pinned === null ? null : ($this->versions->version($particle, $pinned)?->snapshot['payload'] ?? null);
        app(CanvasBodyGuard::class)->assertPublishable(app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->validationEntry($entry, $version->snapshot['meta']['pageSettings'] ?? null, false), $body, JsonDocShape::is($publishedBody) ? $publishedBody : []);
        $before = $mirror ? $this->mirroredFile($entry, is_array($publishedBody) ? $publishedBody : null) : null;
        $this->applyPageSettings($entry, $version);
        app(CompileEntryBody::class)->forVersion($entry, (string) $version->id, $body);
        $entry->setAttribute('published_version', (string) $version->id);
        $entry->save();
        if ($mirror) {
            $this->mirror->mirror($entry, $this->placements->resolve($entry)->pathFor($entry), $body);
            if (($moved = $this->moved($before, $entry)) !== null) {
                $this->mirror->removeIfUnchanged($moved['path'], $moved['bytes']);
            }
        }
        app(CompileEntryBody::class)->artifacts()->prune($entry->refresh());

        return null;
    }

    /** Assign only in memory; the caller persists these with the pin after compilation succeeds. */
    private function applyPageSettings(BeamUxEntry $entry, Version $version, bool $validateUrl = true): void
    {
        $settings = $version->snapshot['meta']['pageSettings'] ?? null;
        if ($settings === null) {
            return; // Historical versions predate page settings; do not invent their old values.
        }
        $data = \Splicewire\Beam\Ux\Data\EntryPageSettingsData::validateAndCreate($settings);
        app(\Splicewire\Beam\Ux\Bindings\RecordPages::class)->assertSettings($entry, $data);
        if (Schema::connection($entry->getConnectionName())->hasColumn('beam_ux_entries', 'record_page')) {
            $entry->setAttribute('record_page', $data->recordPage?->toArray());
        }
        if ($validateUrl) {
            app(EntryUrlChanges::class)->assertAllowed($entry, [$entry->id => [
                'parentId' => $data->parentId, 'segment' => $data->segment,
            ]]);
        }
        $entry->forceFill([
            'title' => $data->title, 'parent_id' => $data->parentId, 'segment' => $data->segment,
            'nav_order' => $data->navOrder, 'nav_group' => $data->navGroup,
            'layout' => $data->layout, 'template' => $data->template,
            'seo_description' => $data->description, 'seo_social_image' => $data->socialImage,
            'seo_page_type' => $data->pageType,
            'seo_published_date' => $data->publishedDate ?? substr((string) ($entry->first_published_at ?? now()), 0, 10),
            'first_published_at' => $entry->first_published_at ?? now(),
            'last_published_at' => now(),
        ]);
        // ADR-0233 d5: the File Language; the mirror moves with it (see mirroredFile()).
        // Checked whether or not it changes: a Hosted site's page names none at all (d6).
        $format = $entry->format instanceof \Splicewire\Beam\Ux\Format\UxFormat ? $entry->format->value : $entry->getAttribute('format');
        if ($data->fileLanguage !== null) {
            if (! \Splicewire\Beam\Ux\Format\FileLanguage::allows($entry, $data->fileLanguage)) {
                throw ValidationException::withMessages(['pageSettings.fileLanguage' => "This page cannot be written in {$data->fileLanguage} on this host."]);
            }
            if ($data->fileLanguage !== $format) {
                $entry->forceFill(['format' => $data->fileLanguage]);
            }
        }
        // ADR-0233 d4: the Page Fields this page declares for its child pages, where the host migrated the column.
        if (Schema::connection($entry->getConnectionName())->hasColumn('beam_ux_entries', 'child_fields')) {
            $entry->forceFill(['child_fields' => $data->childFields]);
        } elseif ($data->childFields !== null) {
            throw ValidationException::withMessages(['pageSettings.childFields' => 'Migrate beam-ux (add_child_fields_to_beam_ux_entries_table) before a page declares Page Fields.']);
        }
    }

    private function compileDraft(BeamUxEntry $entry, Version $version): ?string
    {
        $compiler = app(CompileEntryBody::class);
        try {
            $compiler->forVersion($entry, (string) $version->id, $version->snapshot['payload']);

            return null;
        } catch (CompilationFailed $exception) {
            return $exception->getMessage();
        } finally {
            $compiler->artifacts()->prune($entry->refresh());
        }
    }

    /**
     * ADR-0233 d5 — where an entry's mirror file sits and the bytes it holds, read before Publish applies a version's
     * page settings, so a File Language change can move the file. Null when no mirror is configured or nothing has
     * been published yet.
     *
     * @param  array<string, mixed>|null  $publishedBody
     * @return array{path: string, bytes: string, codec: string}|null
     */
    private function mirroredFile(BeamUxEntry $entry, ?array $publishedBody): ?array
    {
        if (! $this->mirror->enabled() || $publishedBody === null) {
            return null;
        }

        return ['path' => $this->placements->resolve($entry)->pathFor($entry),
            'bytes' => $this->mirror->bytes($entry, $publishedBody), 'codec' => $entry->codec()::class];
    }

    /**
     * The file a publish moved the entry away from, or null when its mirror stays where it was.
     *
     * @param  array{path: string, bytes: string, codec: string}|null  $before
     * @return array{path: string, bytes: string, codec: string}|null
     */
    private function moved(?array $before, BeamUxEntry $entry): ?array
    {
        return $before !== null && $before['path'] !== $this->placements->resolve($entry)->pathFor($entry) ? $before : null;
    }

    /**
     * The two producers every publish ends in, whatever pinned it: project the published body to its
     * placed disk file, then compile the artifact at the entry's current address.
     *
     * Split out because the pin is the only step that needs the version store; every publish reaches this
     * only after {@see requireVersionStore()} has passed and the pin is set, so it never publishes unpinned.
     */
    private function mirrorAndCompile(BeamUxEntry $entry, bool $mirror = true): ?string
    {
        if ($mirror) {
            $item = $entry->particle_id === null
                ? null
                : $this->drivers->resolveOnEntryConnection($entry)->read((string) $entry->particle_id);

            $this->mirror->mirror(
                $entry,
                $this->placements->resolve($entry)->pathFor($entry),
                $item?->body ?? [],
            );
        }

        try {
            // Resolved lazily rather than injected, so this service still constructs on a host that has
            // bound no compiler at all — the same reason the save op has always resolved it this way.
            app(CompileEntryBody::class)->forEntry($entry->refresh(), force: true);

            return null;
        } catch (CompilationFailed $e) {
            return $e->getMessage();
        }
    }

    /** The entry's has-a particle, or null when nothing has ever been written to it. */
    private function particle(BeamUxEntry $entry): ?BeamParticle
    {
        if ($entry->particle_id === null) {
            return null;
        }

        $versionable = $entry->versionable();

        return $versionable instanceof BeamParticle ? $versionable : null;
    }

    /** The particle, after a write that must have minted one. */
    private function requireParticle(BeamUxEntry $entry): BeamParticle
    {
        $entry->unsetRelation('particle');

        return $this->particle($entry) ?? throw new RuntimeException(
            "Entry [{$entry->getKey()}] has no particle after a write; nothing can be versioned."
        );
    }

    private function authoringDriver(): ParticleStorageDriver
    {
        return new ParticleStorageDriver(new ParticleWriter(
            new PolicyWriteGate(app(Gate::class)),
            app(SchemaTargetResolver::class),
            app(AcceptanceGate::class),
            app(Dispatcher::class),
        ));
    }
}
