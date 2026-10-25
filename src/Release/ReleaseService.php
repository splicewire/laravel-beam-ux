<?php

namespace Splicewire\Beam\Ux\Release;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Preview\ReleasePreviewLinks;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\TransitionResult;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;

/** ADR-0226: all writes are on the Release's connection and serialized by its row lock. */
final class ReleaseService
{
    /**
     * ADR-0226 d1: the editor and Workflow tab use the same Release admission as batches.
     *
     * A direct Publish or Take-down is a Release of one with `origin = direct`, recorded on the Release row. Every
     * check on it, now and at a later approve / schedule / publish (review opted in), decides on ONE ability for its
     * entry: the publish ladder (`publish`, else `update`, else `ux.author`; see entryAbility()). Checking edit first
     * would let a host policy's `update` refuse before its own `publish` decision could govern, which is stricter than
     * the pre-Releases Publish (orch-peer, 2026-10-18). A Release built member by member keeps separate edit and
     * publish checks.
     */
    public function requestOne(BeamUxEntry $entry, ?string $ref, Authenticatable $actor): BeamUxRelease
    {
        $connection = $entry->getConnection();
        $initialDepth = $connection->transactionLevel();
        $bodyCompleted = false;
        try {
            return $connection->transaction(function () use ($entry, $ref, $actor, &$bodyCompleted) {
                $release = $this->open($entry, ($ref === null ? 'Take down: ' : 'Publish: ').$entry->slug, $actor, ReleaseOrigin::Direct);
                $this->place($release, $entry->id, $ref, $actor, null, true);
                $this->transition($release, config('beam.ux.releases.review_required', false) ? 'submit' : 'publish', $actor);
                $result = $release->fresh();
                $bodyCompleted = true;

                return $result;
            });
        } catch (\Illuminate\Database\QueryException $failure) {
            // Only a failed SQLite statement in this rolled-back request is retryable. In
            // particular, never relabel a commit/after-commit/Git failure as an unpublished result.
            $code = (int) ($failure->errorInfo[1] ?? 0);
            if (! $bodyCompleted && $initialDepth === 0 && $connection->transactionLevel() === 0
                && $connection->getDriverName() === 'sqlite' && in_array($code & 0xFF, [5, 6], true)) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                    'Publication conflicted with another local write. Nothing was published. Reload and explicitly retry.', $failure);
            }
            throw $failure;
        }
    }

    /** A Release built member by member: every member operation is an edit. */
    public function create(BeamUxEntry $context, string $title, Authenticatable $actor): BeamUxRelease
    {
        return $this->open($context, $title, $actor, ReleaseOrigin::Built);
    }

    /** Only create() (`built`) and requestOne() (`direct`) open a Release; `$origin` is never caller-supplied. */
    private function open(BeamUxEntry $context, string $title, Authenticatable $actor, ReleaseOrigin $origin): BeamUxRelease
    {
        $origin === ReleaseOrigin::Direct
            ? $this->authorizeDirect($context, $actor)
            : $this->authorizeEntry($context, $actor, false);
        if (trim($title) === '' || mb_strlen($title) > 255) {
            $this->refuse('A Release needs a title of at most 255 characters.');
        }
        $release = new BeamUxRelease;
        $release->setConnection($context->getConnectionName());
        app(\Splicewire\Beam\Workflows\Definition\DefinitionStore::class)->onConnection($context->getConnectionName())
            ->ensureSystemLineage(ReleaseLifecycle::DEFINITION, 'UX Release', ReleaseLifecycle::blueprint());
        $release->forceFill(['title' => $title, 'realm' => $context->realm, 'created_by' => $this->token($actor), 'edited_by' => $this->token($actor), 'status' => 'draft', 'origin' => $origin])->save();

        return $release;
    }

    /** Add or change a member by hand: always an edit, on a direct Release too. */
    public function putMember(BeamUxRelease $release, string $entryId, ?string $ref, Authenticatable $actor, ?string $redirectTarget = null): void
    {
        $this->place($release, $entryId, $ref, $actor, $redirectTarget, false);
    }

    /**
     * `$directFirst` is true only from requestOne, for the one member of the direct Release it just opened: that
     * placement is part of the direct request and decides on the publish ladder. Every other placement, including a
     * re-add after the member was dropped, is an edit (orch-peer, 2026-10-18).
     */
    private function place(BeamUxRelease $release, string $entryId, ?string $ref, Authenticatable $actor, ?string $redirectTarget, bool $directFirst): void
    {
        $release->getConnection()->transaction(function () use ($release, $entryId, $ref, $actor, $redirectTarget, $directFirst) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $this->assertEditable($locked);
            $entry = $this->entry($locked, $entryId);
            $directFirst && $this->isDirect($locked)
                ? $this->authorizeDirect($entry, $actor)
                : $this->authorizeEntry($entry, $actor, false);
            $this->assertMember($entry, $ref, $redirectTarget, $this->publishing($locked));
            $this->transition($locked, 'revise', $actor);
            $member = $locked->members()->where('entry_id', $entryId)->first()
                ?? (new BeamUxReleaseMember)->setConnection($locked->getConnectionName());
            $member->forceFill([
                'release_id' => $locked->id, 'entry_id' => $entryId, 'version_id' => $ref,
                'observed_pin' => $entry->published_version, 'observed_marking' => $entry->workflow_marking,
                'redirect_target' => $redirectTarget,
            ])->save();
        });
    }

    /**
     * ADR-0232 d8: turn the Release's Preview Links on or off. Anyone who can edit its members may. Links change no
     * member, so a review stands. Off clears the secret, so turning them on again ends every earlier link.
     */
    public function previewLinks(BeamUxRelease $release, bool $on, Authenticatable $actor): void
    {
        $release->getConnection()->transaction(function () use ($release, $on, $actor) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $this->assertEditable($locked);
            $this->authorizeLinks($locked, $actor);
            $locked->forceFill($on
                ? ['preview_links_enabled' => true, 'preview_link_secret' => $locked->getAttribute('preview_link_secret') ?: ReleasePreviewLinks::newSecret()]
                : ['preview_links_enabled' => false, 'preview_link_secret' => null])->save();
        });
    }

    /** Rotating the secret ends every link to the Release, and every Preview a link opened, at once. */
    public function rotatePreviewLinks(BeamUxRelease $release, Authenticatable $actor): void
    {
        $release->getConnection()->transaction(function () use ($release, $actor) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $this->assertEditable($locked);
            $this->authorizeLinks($locked, $actor);
            if (! $locked->getAttribute('preview_links_enabled')) {
                $this->refuse('Turn Preview Links on before rotating them.');
            }
            $locked->forceFill(['preview_link_secret' => ReleasePreviewLinks::newSecret()])->save();
        });
    }

    public function dropMember(BeamUxRelease $release, string $entryId, Authenticatable $actor): void
    {
        $release->getConnection()->transaction(function () use ($release, $entryId, $actor) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $this->assertEditable($locked);
            $this->authorizeEntry($this->entry($locked, $entryId), $actor, false);
            $this->transition($locked, 'revise', $actor);
            $locked->members()->where('entry_id', $entryId)->delete();
        });
    }

    /** Rebase keeps the selected version; use-latest explicitly changes it. Both revoke approval. */
    public function rebase(BeamUxRelease $release, string $entryId, Authenticatable $actor, bool $useLatest = false): void
    {
        $release->getConnection()->transaction(function () use ($release, $entryId, $actor, $useLatest) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $member = $locked->members()->where('entry_id', $entryId)->firstOrFail();
            $entry = $this->entry($locked, $entryId);
            $ref = $useLatest ? app(EntryPublication::class)->state($entry)->headVersion : $member->version_id;
            if ($useLatest && $ref === null) {
                $this->refuse('The entry has no saved version.');
            }
            $this->putMember($locked, $entryId, $ref, $actor, $member->redirect_target);
        });
    }

    public function transition(BeamUxRelease $release, string $transition, Authenticatable $actor): TransitionResult
    {
        $result = app(WorkflowActuator::class)->transition($release, $transition, new TransitionContext(actor: $this->token($actor)));
        if (! $result->applied) {
            $this->refuse('The Release workflow refused '.$transition.': '.implode('; ', $result->blockers));
        }

        return $result;
    }

    /** Rollback is another reviewed Release selecting the prior pins, not a reverse mutation. */
    public function rollback(BeamUxRelease $source, Authenticatable $actor): BeamUxRelease
    {
        return $source->getConnection()->transaction(function () use ($source, $actor) {
            $locked = $source->newQuery()->lockForUpdate()->findOrFail($source->id);
            if ($locked->status !== 'published') {
                $this->refuse('Only a published Release can produce a rollback Release.');
            }
            $members = $locked->members()->orderBy('entry_id')->get();
            if ($members->isEmpty()) {
                $this->refuse('The Release has no members to roll back.');
            }
            $rollback = $this->create($this->entry($locked, $members->first()->entry_id), 'Rollback: '.$locked->title, $actor);
            $rollback->forceFill(['rollback_of' => $locked->id])->save();
            foreach ($members as $member) {
                $ref = $member->observed_pin;
                // A previously hidden managed entry must be taken down, even if it retained a pin.
                if ($member->observed_marking !== null && $member->observed_marking !== BeamUxEntry::MARKING_PUBLISHED) {
                    $ref = null;
                }
                $this->putMember($rollback, $member->entry_id, $ref, $actor);
            }

            return $rollback->fresh();
        });
    }

    public function schedule(BeamUxRelease $release, string $dueAt, string $timezone, Authenticatable $actor): void
    {
        if (! app()->bound(\Splicewire\Beam\Calendars\Actions\ActionService::class)) {
            $this->refuse('Scheduled publication requires the calendars ActionService. Publish now is available without it.');
        }
        $release->getConnection()->transaction(function () use ($release, $dueAt, $timezone, $actor) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $context = app(\Splicewire\Beam\Calendars\Contracts\ActionContextProvider::class)->current();
            if ($context->principal !== $this->token($actor)) {
                throw new AuthorizationException('Scheduled publication must use the current author principal.');
            }
            $token = 'ux-release:'.$locked->id.':'.$locked->revision;
            $locked->forceFill(['schedule_token' => $token])->save();
            $this->transition($locked, 'schedule', $actor);
            app(\Splicewire\Beam\Calendars\Actions\ActionService::class)->schedule(
                new \Splicewire\Beam\Calendars\Data\CalendarActionData(
                    'kind.workflow-transition', ['subject_kind' => 'beam-ux-release', 'subject_id' => $locked->id, 'transition' => 'publish'],
                    $dueAt, $timezone, origin: $token,
                ), $context, $locked->getConnection(),
            );
        });
    }

    /** Called under the workflow subject lock; scheduled actions use exactly this path too. */
    public function participate(BeamUxRelease $release, string $transition, TransitionContext $context): void
    {
        $actor = $this->actor($context->actor);
        $members = $release->members()->orderBy('entry_id')->get();
        if ($transition !== 'revise' && $members->isEmpty()) {
            $this->refuse('An empty Release cannot advance.');
        }
        $removed = $members->whereNull('version_id')->pluck('entry_id')->all();
        // Lock the same final graph in the same order as EntryUrlChanges / batch publication.
        $prototype = new BeamUxEntry;
        $prototype->setConnection($release->getConnectionName());
        $prototype->newQuery()->orderBy('id')->lockForUpdate()->get();
        foreach ($members as $member) {
            $entry = $this->entry($release, $member->entry_id);
            $this->isDirect($release)
                ? $this->authorizeDirect($entry, $actor)
                : $this->authorizeEntry($entry, $actor, in_array($transition, ['approve', 'schedule', 'publish'], true));
            if ($transition === 'publish') {
                if ($this->memberMoved($entry, $member)) {
                    $this->refuse('A publication pin or marking moved. Rebase or drop that member.');
                }
                $this->assertMemberPublishable($release, $entry, $member, $removed);
            }
        }
        if ($transition === 'revise') {
            // An empty Release still has an identified author, never an anonymous transition.
            Gate::forUser($actor)->authorize('ux.author');
            $release->reviewed_by = null;
            $release->edited_by = $context->actor;
            $release->revision = ((int) $release->revision) + 1;
            $release->schedule_token = null;
        } elseif ($transition === 'approve') {
            if (! config('beam.ux.releases.allow_self_approval', false) && in_array($context->actor, [$release->created_by, $release->edited_by], true)) {
                throw new AuthorizationException('A different author must review this Release.');
            }
            $release->reviewed_by = $context->actor;
        } elseif ($transition === 'schedule') {
            if (! app()->bound(\Splicewire\Beam\Calendars\Actions\ActionService::class) || $release->schedule_token === null) {
                $this->refuse('Schedule this Release through the calendars action service.');
            }
        } elseif ($transition === 'publish') {
            if (str_starts_with($context->causationId ?? '', 'ux-release:') && $context->causationId !== $release->schedule_token) {
                throw new AuthorizationException('The scheduled Release membership changed; schedule the reviewed revision again.');
            }
            if ($this->awaitsReview($release)) {
                $this->refuse('This Release requires approval before publication.');
            }
            // ADR-0232 d8: Publish ends the Preview Links; the secret goes with them (saved with the transition).
            if ($release->getAttribute('preview_links_enabled') || $release->getAttribute('preview_link_secret') !== null) {
                $release->forceFill(['preview_links_enabled' => false, 'preview_link_secret' => null]);
            }
            $refs = $pins = [];
            foreach ($members as $member) {
                if ($member->version_id !== null) {
                    $refs[$member->entry_id] = $member->version_id;
                    $pins[$member->entry_id] = $member->observed_pin;
                }
            }
            $takedowns = [];
            foreach ($members as $member) {
                if ($member->version_id === null) {
                    $entry = $this->entry($release, $member->entry_id);
                    $pin = $entry->published_version;
                    $version = $pin === null || $entry->particle === null
                        ? null : app(VersionStore::class)->version($entry->particle, $pin);
                    if ($version === null || ! is_array($version->snapshot['payload'] ?? null)) {
                        $this->refuse('A Take-down requires the currently published body snapshot.');
                    }
                    $takedowns[] = [$entry, $version, $version->snapshot['payload']];
                }
            }
            // ADR-0236 d2, d4: the Redirects the Release leaves, its moves' and its Take-downs', read before anything
            // changes and written once its Take-downs are done.
            $redirects = app(\Splicewire\Beam\Ux\Redirect\ReleaseRedirects::class)->plan($prototype, $refs,
                $members->whereNull('version_id')->mapWithKeys(fn ($member) => [(string) $member->entry_id => $member->redirect_target])->all());
            if ($refs !== []) {
                app(EntryPublication::class)->publishVersions($prototype, $refs, $actor instanceof Model ? $actor : null, $pins,
                    fn (array $prepared) => app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->prepare($release, $prepared, $actor, $takedowns),
                    leaveRedirects: false);
            } elseif ($takedowns !== []) {
                app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->prepare($release, [], $actor, $takedowns);
            }
            foreach ($members as $member) {
                if ($member->version_id === null) {
                    $entry = $this->entry($release, $member->entry_id);
                    $result = app(WorkflowActuator::class)->transition($entry, 'unpublish', clone $context);
                    if (! $result->applied) {
                        $this->refuse('The entry lifecycle refused Take-down.');
                    }
                }
            }
            app(\Splicewire\Beam\Ux\Redirect\RedirectService::class)->leave($redirects, 'release', (string) $release->getKey(),
                $actor instanceof Model ? $actor : null, $release->getConnectionName());
        }
    }

    /** Whether review is on and this Release has not been approved; Publish refuses it (ADR-0226). */
    public function awaitsReview(BeamUxRelease $release): bool
    {
        return config('beam.ux.releases.review_required', false)
            && (! in_array($release->status, ['approved', 'scheduled'], true) || $release->reviewed_by === null);
    }

    /** A stale member: its entry's publication pin or marking moved after it joined (ADR-0226 d6). */
    public function memberMoved(BeamUxEntry $entry, BeamUxReleaseMember $member): bool
    {
        return $entry->published_version !== $member->observed_pin || $entry->workflow_marking !== $member->observed_marking;
    }

    /**
     * The member checks Publish makes once the member is current: its fixed version still reads and compiles, or its
     * Take-down is admissible and does not redirect to a page the same Release takes down. Publish and the readiness
     * report (ADR-0232 d11) both run it.
     *
     * @param  list<string>  $removed  the entries the Release takes down
     */
    public function assertMemberPublishable(BeamUxRelease $release, BeamUxEntry $entry, BeamUxReleaseMember $member, array $removed): void
    {
        $this->assertMember($entry, $member->version_id, $member->redirect_target, $this->publishing($release));
        if ($member->redirect_target !== null) {
            foreach ($removed as $removedId) {
                $target = $this->entry($release, $removedId);
                if ($member->redirect_target === $target->id || $member->redirect_target === $target->url()) {
                    $this->refuse('A redirect target cannot be taken down in the same Release.');
                }
            }
            // ADR-0236 d4, EP-31 ruling 4: the target is live once the Release publishes, so not under a page it hides.
            $members = $release->members()->get();
            $planner = app(\Splicewire\Beam\Ux\Redirect\ReleaseRedirects::class);
            $prototype = (new BeamUxEntry)->setConnection($release->getConnectionName());
            $target = $planner->targetOf($prototype, $member->redirect_target);
            $refs = $members->whereNotNull('version_id')->mapWithKeys(fn ($m) => [(string) $m->entry_id => (string) $m->version_id])->all();
            $takedowns = $members->whereNull('version_id')->mapWithKeys(fn ($m) => [(string) $m->entry_id => $m->redirect_target])->all();
            if ($target === null || ! $planner->liveAfter($prototype, $refs, $takedowns, $target)) {
                $this->refuse('The Take-down redirect target must be live once this Release publishes: published, and not under a page it hides.');
            }
        }
    }

    /** @return list<string> the entries the Release publishes a version of */
    private function publishing(BeamUxRelease $release): array
    {
        return $release->members()->whereNotNull('version_id')->pluck('entry_id')->map(fn ($id) => (string) $id)->all();
    }

    /** @param  list<string>  $publishing  the entries the Release publishes, which count as published targets (EP-31 ruling 4) */
    private function assertMember(BeamUxEntry $entry, ?string $ref, ?string $redirectTarget, array $publishing = []): void
    {
        if ($ref !== null) {
            if ($redirectTarget !== null) {
                $this->refuse('Only a Take-down carries a redirect target.');
            }
            $version = $entry->particle === null ? null : app(VersionStore::class)->version($entry->particle, $ref);
            if ($version === null || ! is_array($version->snapshot['payload'] ?? null)) {
                $this->refuse('The selected saved version does not belong to this entry.');
            }
            app(CompileEntryBody::class)->forVersion($entry, $ref, $version->snapshot['payload']);

            return;
        }
        $workflow = app(WorkflowActuator::class);
        if (! $workflow->manages($entry) || ! in_array('unpublish', $workflow->available($entry), true)) {
            $this->refuse('Take-down requires an entry lifecycle that currently permits unpublish.');
        }
        if ($redirectTarget !== null) {
            $target = $entry->newQuery()->where(fn ($query) => $query->whereNotNull('published_version')->orWhereIn('id', $publishing))->get()
                ->first(function (BeamUxEntry $candidate) use ($entry, $redirectTarget, $workflow, $publishing) {
                    return $candidate->id !== $entry->id && $candidate->realm === $entry->realm
                        && (! $workflow->manages($candidate) || $candidate->workflow_marking === BeamUxEntry::MARKING_PUBLISHED
                            || in_array((string) $candidate->id, $publishing, true))
                        && ($candidate->id === $redirectTarget || $candidate->url() === $redirectTarget);
                });
            if ($target === null) {
                $this->refuse('The Take-down redirect must resolve to a published entry in this realm, or one this Release publishes.');
            }
        }
        // Admission only: the Release publishes the Redirects (ADR-0236 d4), once assertMemberPublishable() finds the
        // target live after it.
    }

    /** A member's entry, which must share the Release's realm and connection. */
    public function entry(BeamUxRelease $release, string $id): BeamUxEntry
    {
        $entry = BeamUxEntry::on($release->getConnectionName())->findOrFail($id);
        if ($entry->realm !== $release->realm) {
            $this->refuse('Release members must belong to its realm and connection.');
        }

        return $entry;
    }

    /** Shared by initial completion and Files recovery; no recording privilege is introduced. */
    public function authorizeRecording(BeamUxRelease $release, Authenticatable $actor): void
    {
        if ($release->status !== 'published' || $release->members()->count() === 0) {
            throw new AuthorizationException('Only a published nonempty Release can be recorded.');
        }
        foreach ($release->members()->get() as $member) {
            $entry = $this->entry($release, $member->entry_id);
            $this->isDirect($release)
                ? $this->authorizeDirect($entry, $actor)
                : $this->authorizeEntry($entry, $actor, true);
        }
    }

    /** File import edits an entry; missing-file restoration additionally requires publication reach. */
    public function authorizeFileEdit(BeamUxEntry $entry, Authenticatable $actor, bool $restore = false): void
    {
        $this->authorizeEntry($entry, $actor, $restore);
    }

    private function authorizeEntry(BeamUxEntry $entry, Authenticatable $actor, bool $publish): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('ux.author');

        foreach ($publish ? ['edit', 'publish'] : ['edit'] as $kind) {
            $ability = $this->entryAbility($entry, $kind);
            if ($ability !== null) {
                $gate->authorize($ability, $entry);
            }
        }
    }

    /** Whether the actor may manage the Release's Preview Links: the check the link actions make, without refusing. */
    public function mayManageLinks(BeamUxRelease $release, ?Authenticatable $actor): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->authorizeLinks($release, $actor);
        } catch (AuthorizationException|ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // A member that can't be read for this Release (gone, another realm) can't be edited either.
            return false;
        }

        return true;
    }

    /** Whoever can edit every member of the Release may manage its Preview Links (ADR-0232 d8). */
    private function authorizeLinks(BeamUxRelease $release, Authenticatable $actor): void
    {
        Gate::forUser($actor)->authorize('ux.author');
        foreach ($release->members()->pluck('entry_id') as $entryId) {
            $this->authorizeEntry($this->entry($release, (string) $entryId), $actor, false);
        }
    }

    /** A direct Release's single check: `ux.author`, then the publish ladder alone. */
    private function authorizeDirect(BeamUxEntry $entry, Authenticatable $actor): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('ux.author');
        $ability = $this->entryAbility($entry, 'publish');
        if ($ability !== null) {
            $gate->authorize($ability, $entry);
        }
    }

    private function isDirect(BeamUxRelease $release): bool
    {
        return $release->getAttribute('origin') === ReleaseOrigin::Direct;
    }

    /**
     * The ability a Release checks on a member for `edit` or `publish`, or null when only `ux.author` applies (owner
     * ruling 2026-10-18: an upgrade never makes Publish answer 403 to an author).
     *
     * A host maps an ability explicitly in `beam.ux.releases.{edit,publish}_ability`; that is checked strictly through
     * the Gate. Otherwise the ability is the method of a policy the HOST registered for BeamUxEntry: `publish` falls
     * back to that policy's `update`, and `update` to nothing beyond `ux.author`. Two things do not count:
     *  - a global `Gate::define` closure for a common name such as `update`: nothing can tell what subject it was
     *    written for, and a host that defines `update` for its own models only would otherwise refuse every author;
     *  - this package's own default, permission-cascade's ConfiguredModelPolicy, which BeamUxEntry's
     *    `#[UseCascadePolicy]` registers on every host. Pre-Releases Publish never consulted it (`ux.author` only), so
     *    counting it would make an upgrade refuse an author who could publish before.
     */
    private function entryAbility(BeamUxEntry $entry, string $kind): ?string
    {
        $configured = config("beam.ux.releases.{$kind}_ability");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $policy = Gate::getPolicyFor($entry);
        if ($policy instanceof ConfiguredModelPolicy) {
            $policy = null;
        }
        foreach ($kind === 'publish' ? ['publish', 'update'] : ['update'] as $method) {
            if ($policy !== null && method_exists($policy, $method)) {
                return $method;
            }
        }

        return null;
    }

    private function actor(?string $token): Authenticatable
    {
        $actor = $token !== null && str_starts_with($token, 'user:')
            ? $this->userProvider()?->retrieveById(substr($token, 5)) : null;
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException('A Release requires a currently resolvable author.');
        }

        return $actor;
    }

    private function userProvider(): ?UserProvider
    {
        try {
            $guardName = config('auth.defaults.guard');
            $providerName = is_string($guardName) && $guardName !== ''
                ? config("auth.guards.{$guardName}.provider")
                : null;
            if (is_string($providerName) && $providerName !== '') {
                $provider = Auth::createUserProvider($providerName);
                if ($provider instanceof UserProvider) {
                    return $provider;
                }
            }

            $guard = Auth::guard(is_string($guardName) && $guardName !== '' ? $guardName : null);
            if (method_exists($guard, 'getProvider')) {
                $provider = $guard->getProvider();
                if ($provider instanceof UserProvider) {
                    return $provider;
                }
            }

            return Auth::createUserProvider();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function token(Authenticatable $actor): string
    {
        return 'user:'.$actor->getAuthIdentifier();
    }

    private function assertEditable(BeamUxRelease $release): void
    {
        if ($release->status === 'published') {
            $this->refuse('A published Release is immutable; create a rollback Release instead.');
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['release' => $message]);
    }
}
