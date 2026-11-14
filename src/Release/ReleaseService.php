<?php

namespace Splicewire\Beam\Ux\Release;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Preview\ReleasePreviewLinks;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\Kinds\EntryMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseAccess;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseMemberKindRegistry;
use Splicewire\Beam\Ux\Support\EditorAuthorizer;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\TransitionResult;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;

/**
 * ADR-0226: all writes are on the Release's connection and serialized by its row lock.
 *
 * @api
 */
final class ReleaseService
{
    /**
     * ADR-0226 d1: the editor and Workflow tab use the same Release admission as batches.
     *
     * A direct Publish or Take-down is a Release of one with `origin = direct`, recorded on the Release row. Every
     * check on it, now and at a later approve / schedule / publish (review opted in), decides on ONE ability for its
     * entry: the publish ladder (`publish`, else `update`, else `ux.author`; see EntryMemberKind::entryAbility()). Checking edit first
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
                $this->place($release, BeamUxReleaseMember::KIND_ENTRY, $entry->id, $ref, $actor, null, true);
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
                throw new \Splicewire\Beam\Ux\Localization\CodedConflictHttpException(ReleaseError::PublicationConflicted, [], $failure);
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
            ? $this->entries()->authorizeDirect($context, $actor)
            : $this->entries()->authorizeEntry($context, $actor, false);
        if (trim($title) === '' || mb_strlen($title) > 255) {
            $this->refuse(ReleaseError::TitleTooLong);
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
        $this->place($release, BeamUxReleaseMember::KIND_ENTRY, $entryId, $ref, $actor, $redirectTarget, false);
    }

    /**
     * `$directFirst` is true only from requestOne, for the one member of the direct Release it just opened: that
     * placement is part of the direct request and decides on the publish ladder. Every other placement, including a
     * re-add after the member was dropped, is an edit (orch-peer, 2026-10-18).
     */
    private function place(BeamUxRelease $release, string $kindName, string $ref, ?string $version, Authenticatable $actor, ?string $redirectTarget, bool $directFirst): void
    {
        $release->getConnection()->transaction(function () use ($release, $kindName, $ref, $version, $actor, $redirectTarget, $directFirst) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $this->assertEditable($locked);
            $kind = $this->kinds()->for($kindName);
            $subject = $kind->subject($locked, $ref);
            $kind->authorize($locked, $subject, $actor, $directFirst && $this->isDirect($locked) ? ReleaseAccess::Direct : ReleaseAccess::Edit);
            $kind->admit($locked, $subject, (new BeamUxReleaseMember)->forceFill(['version_id' => $version, 'redirect_target' => $redirectTarget]));
            $this->transition($locked, 'revise', $actor);
            // An entry member is found by its entry_id, which every writer has always set.
            $member = ($kindName === BeamUxReleaseMember::KIND_ENTRY
                ? $locked->members()->where('entry_id', $ref)
                : $locked->allMembers()->where('member_kind', $kindName)->where('member_ref', $ref))->first()
                ?? (new BeamUxReleaseMember)->setConnection($locked->getConnectionName());
            $member->forceFill([
                'release_id' => $locked->id, 'member_kind' => $kindName, 'member_ref' => $ref, 'version_id' => $version,
                'redirect_target' => $redirectTarget,
            ] + $kind->columns($subject))->save();
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
                $this->refuse(ReleaseError::PreviewLinksOff);
            }
            $locked->forceFill(['preview_link_secret' => ReleasePreviewLinks::newSecret()])->save();
        });
    }

    public function dropMember(BeamUxRelease $release, string $entryId, Authenticatable $actor): void
    {
        $release->getConnection()->transaction(function () use ($release, $entryId, $actor) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $this->assertEditable($locked);
            $this->entries()->authorizeEntry($this->entry($locked, $entryId), $actor, false);
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
                $this->refuse(ReleaseError::EntryNoSavedVersion);
            }
            $this->putMember($locked, $entryId, $ref, $actor, $member->redirect_target);
        });
    }

    public function transition(BeamUxRelease $release, string $transition, Authenticatable $actor): TransitionResult
    {
        $result = app(WorkflowActuator::class)->transition($release, $transition, new TransitionContext(actor: $this->token($actor)));
        if (! $result->applied) {
            // The workflow's blockers are its own words, passed through as a parameter.
            $this->refuse(ReleaseError::WorkflowRefused, ['transition' => $transition, 'blockers' => implode('; ', $result->blockers)]);
        }

        return $result;
    }

    /**
     * Rollback is another reviewed Release selecting the prior pins, not a reverse mutation. Each member's kind says
     * what it selects (ADR-0243). The rollback Release opens on an entry member's entry, as every Release does today.
     */
    public function rollback(BeamUxRelease $source, Authenticatable $actor): BeamUxRelease
    {
        return $source->getConnection()->transaction(function () use ($source, $actor) {
            $locked = $source->newQuery()->lockForUpdate()->findOrFail($source->id);
            if ($locked->status !== 'published') {
                $this->refuse(ReleaseError::RollbackNeedsPublished);
            }
            $members = $this->membersByKind($locked);
            if ($members->isEmpty()) {
                $this->refuse(ReleaseError::RollbackNoMembers);
            }
            $context = $members->first(fn (BeamUxReleaseMember $member) => $member->kind() === BeamUxReleaseMember::KIND_ENTRY);
            if ($context === null) {
                $this->refuse(ReleaseError::RollbackNoPages);
            }
            $rollback = $this->create($this->entry($locked, $context->entry_id), 'Rollback: '.$locked->title, $actor);
            $rollback->forceFill(['rollback_of' => $locked->id])->save();
            foreach ($members as $member) {
                $this->place($rollback, $member->kind(), $member->ref(), $this->kinds()->for($member->kind())->rollbackRef($member), $actor, null, false);
            }

            return $rollback->fresh();
        });
    }

    public function schedule(BeamUxRelease $release, string $dueAt, string $timezone, Authenticatable $actor): void
    {
        if (! app()->bound(\Splicewire\Beam\Calendars\Actions\ActionService::class)) {
            $this->refuse(ReleaseError::SchedulingNeedsCalendars);
        }
        $release->getConnection()->transaction(function () use ($release, $dueAt, $timezone, $actor) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $context = app(\Splicewire\Beam\Calendars\Contracts\ActionContextProvider::class)->current();
            if ($context->principal !== $this->token($actor)) {
                throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::ScheduleAuthorPrincipal);
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

    /**
     * Called under the workflow subject lock; scheduled actions use exactly this path too. The Release decides what
     * every member shares (emptiness, review, schedule, Preview Links); each member's kind checks and publishes its own
     * (ADR-0243).
     */
    public function participate(BeamUxRelease $release, string $transition, TransitionContext $context): void
    {
        $actor = $this->actor($context->actor);
        $members = $this->membersByKind($release);
        if ($transition !== 'revise' && $members->isEmpty()) {
            $this->refuse(ReleaseError::Empty);
        }
        $kinds = $this->kinds();
        foreach ($members as $member) {
            $kinds->for($member->kind());
        }
        foreach ($kinds->all() as $kind) {
            $kind->lock($release);
        }
        $access = $this->isDirect($release) ? ReleaseAccess::Direct
            : (in_array($transition, ['approve', 'schedule', 'publish'], true) ? ReleaseAccess::Publish : ReleaseAccess::Edit);
        foreach ($members as $member) {
            $kind = $kinds->for($member->kind());
            $subject = $kind->subject($release, $member->ref());
            $kind->authorize($release, $subject, $actor, $access);
            if ($transition === 'publish') {
                if ($kind->moved($subject, $member)) {
                    $this->refuse(ReleaseError::PinOrMarkingMoved);
                }
                $kind->assertPublishable($release, $subject, $member);
            }
        }
        if ($transition === 'revise') {
            // An empty Release still has an identified author, never an anonymous transition.
            app(EditorAuthorizer::class)->authorize($actor);
            $release->reviewed_by = null;
            $release->edited_by = $context->actor;
            $release->revision = ((int) $release->revision) + 1;
            $release->schedule_token = null;
        } elseif ($transition === 'approve') {
            if (! config('beam.ux.releases.allow_self_approval', false) && in_array($context->actor, [$release->created_by, $release->edited_by], true)) {
                throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::DifferentReviewer);
            }
            $release->reviewed_by = $context->actor;
        } elseif ($transition === 'schedule') {
            if (! app()->bound(\Splicewire\Beam\Calendars\Actions\ActionService::class) || $release->schedule_token === null) {
                $this->refuse(ReleaseError::ScheduleThroughCalendars);
            }
        } elseif ($transition === 'publish') {
            if (str_starts_with($context->causationId ?? '', 'ux-release:') && $context->causationId !== $release->schedule_token) {
                throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::ScheduleMembershipChanged);
            }
            if ($this->awaitsReview($release)) {
                $this->refuse(ReleaseError::ApprovalRequired);
            }
            // ADR-0232 d8: Publish ends the Preview Links; the secret goes with them (saved with the transition).
            if ($release->getAttribute('preview_links_enabled') || $release->getAttribute('preview_link_secret') !== null) {
                $release->forceFill(['preview_links_enabled' => false, 'preview_link_secret' => null]);
            }
            $grouped = $members->groupBy(fn (BeamUxReleaseMember $member) => $member->kind());
            foreach ($kinds->all() as $kind) {
                if ($grouped->has($kind->kind())) {
                    $kind->publish($release, $grouped->get($kind->kind())->values(), $actor, $context);
                }
            }
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
        return $this->entries()->moved($entry, $member);
    }

    /**
     * The entry member checks Publish makes once the member is current ({@see EntryMemberKind::assertPublishableAgainst()}).
     * Publish and the readiness report (ADR-0232 d11) both run it.
     *
     * @param  list<string>  $removed  the entries the Release takes down
     */
    public function assertMemberPublishable(BeamUxRelease $release, BeamUxEntry $entry, BeamUxReleaseMember $member, array $removed): void
    {
        $this->entries()->assertPublishableAgainst($release, $entry, $member, $removed);
    }

    /** A member's entry, which must share the Release's realm and connection. */
    public function entry(BeamUxRelease $release, string $id): BeamUxEntry
    {
        return $this->entries()->subject($release, $id);
    }

    /** Shared by initial completion and Files recovery; no recording privilege is introduced. */
    public function authorizeRecording(BeamUxRelease $release, Authenticatable $actor): void
    {
        $members = $this->membersByKind($release);
        if ($release->status !== 'published' || $members->isEmpty()) {
            throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::RecordNeedsPublished);
        }
        $this->authorizeMembers($release, $members, $actor, $this->isDirect($release) ? ReleaseAccess::Direct : ReleaseAccess::Publish);
    }

    /** File import edits an entry; missing-file restoration additionally requires publication reach. */
    public function authorizeFileEdit(BeamUxEntry $entry, Authenticatable $actor, bool $restore = false): void
    {
        $this->entries()->authorizeEntry($entry, $actor, $restore);
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
        app(EditorAuthorizer::class)->authorize($actor);
        $this->authorizeMembers($release, $this->membersByKind($release), $actor, ReleaseAccess::Edit);
    }

    /** @param  Collection<int, BeamUxReleaseMember>  $members */
    private function authorizeMembers(BeamUxRelease $release, Collection $members, Authenticatable $actor, ReleaseAccess $access): void
    {
        foreach ($members as $member) {
            $kind = $this->kinds()->for($member->kind());
            $kind->authorize($release, $kind->subject($release, $member->ref()), $actor, $access);
        }
    }

    /**
     * Every member of every kind, entries first by entry_id as before, then each other kind by its ref. Sorted here,
     * not in SQL, so a members table that predates the kind columns still reads (its rows are all entries).
     *
     * @return Collection<int, BeamUxReleaseMember>
     */
    private function membersByKind(BeamUxRelease $release): Collection
    {
        $order = array_flip($this->kinds()->kinds());

        return $release->allMembers()->orderBy('entry_id')->get()
            ->sortBy(fn (BeamUxReleaseMember $member) => [$order[$member->kind()] ?? PHP_INT_MAX, $member->kind(), $member->kind() === BeamUxReleaseMember::KIND_ENTRY ? '' : $member->ref()])
            ->values();
    }

    private function kinds(): ReleaseMemberKindRegistry
    {
        return app(ReleaseMemberKindRegistry::class);
    }

    private function entries(): EntryMemberKind
    {
        $kind = $this->kinds()->for(BeamUxReleaseMember::KIND_ENTRY);
        if (! $kind instanceof EntryMemberKind) {
            $this->refuse(ReleaseError::EntryKindMismatch);
        }

        return $kind;
    }

    private function isDirect(BeamUxRelease $release): bool
    {
        return $release->getAttribute('origin') === ReleaseOrigin::Direct;
    }

    private function actor(?string $token): Authenticatable
    {
        $actor = $this->resolveActorToken($token);
        if (! $actor instanceof Authenticatable) {
            throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::AuthorUnresolvable);
        }

        return $actor;
    }

    /** The signed, tenant Preview handoff reuses the Release workflow's actor identity encoding. */
    public function actorTokenFor(Authenticatable $actor): string
    {
        return $this->token($actor);
    }

    /** Resolve a workflow actor token, returning null when its identity cannot be proven in this context. */
    public function resolveActorToken(?string $token): ?Authenticatable
    {
        $actor = match (true) {
            $token !== null && str_starts_with($token, 'user:') => $this->userProvider()?->retrieveById(substr($token, 5)),
            $token !== null && str_starts_with($token, 'tenant-model:') => $this->tenantModelActor($token),
            $token !== null && str_starts_with($token, 'model:') => $this->modelActor($token),
            default => null,
        };

        return $actor instanceof Authenticatable ? $actor : null;
    }

    /** Resolve a tenant actor only while that exact tenant is initialized in this process. */
    private function tenantModelActor(string $token): ?Authenticatable
    {
        $parts = explode(':', $token, 5);
        if (count($parts) !== 5) {
            return null;
        }

        [, $encodedTenantId, $encodedModel] = $parts;
        $tenantId = base64_decode($encodedTenantId, true);
        if (! is_string($tenantId)) {
            return null;
        }

        $tenancy = app(TenancyContext::class);
        if (! $tenancy->isActive() || (string) $tenancy->currentTenantId() !== $tenantId) {
            return null;
        }

        return $this->modelActor('model:'.$encodedModel.':'.$parts[3].':'.$parts[4]);
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

    /** Resolve an explicitly typed non-default auth model without falling back across user stores. */
    private function modelActor(string $token): ?Authenticatable
    {
        $parts = explode(':', $token, 4);
        if (count($parts) !== 4) {
            return null;
        }

        [, $encodedClass, $encodedConnection, $encodedId] = $parts;
        $class = base64_decode($encodedClass, true);
        $connection = base64_decode($encodedConnection, true);
        $id = base64_decode($encodedId, true);
        if (! is_string($class) || ! class_exists($class)
            || ! is_subclass_of($class, Model::class)
            || ! is_subclass_of($class, Authenticatable::class)
            || ! is_string($connection) || $connection === '' || ! is_string($id)) {
            return null;
        }

        $model = new $class;
        $model->setConnection($connection);
        $actor = $model->newQuery()->where($model->getAuthIdentifierName(), $id)->first();

        return $actor instanceof Authenticatable ? $actor : null;
    }

    private function token(Authenticatable $actor): string
    {
        if ($actor instanceof Model) {
            $connection = $actor->getConnection()->getName();
            $tenancy = app(TenancyContext::class);
            $centralConnection = $tenancy->centralConnection() ?? config('database.default');

            if ($tenancy->isActive() || ($tenancy->isMultiTenant() && $connection !== $centralConnection)) {
                if (! $tenancy->isMultiTenant() || ! $tenancy->isActive()) {
                    throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::TenantIdentityRequired);
                }

                $tenantId = $tenancy->currentTenantId();
                if ($tenantId === null) {
                    throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::TenantIdentityRequired);
                }

                return 'tenant-model:'.base64_encode((string) $tenantId)
                    .':'.base64_encode($actor::class)
                    .':'.base64_encode($connection)
                    .':'.base64_encode((string) $actor->getAuthIdentifier());
            }

            $defaultModel = $this->defaultAuthModel();
            if ($defaultModel !== null && $actor::class !== $defaultModel) {
                return 'model:'.base64_encode($actor::class)
                    .':'.base64_encode($connection)
                    .':'.base64_encode((string) $actor->getAuthIdentifier());
            }

            return 'user:'.$actor->getAuthIdentifier();
        }

        if (app(TenancyContext::class)->isActive()) {
            throw new \Splicewire\Beam\Localization\CodedAuthorizationException(ReleaseError::TenantIdentityRequired);
        }

        return 'user:'.$actor->getAuthIdentifier();
    }

    private function defaultAuthModel(): ?string
    {
        $guardName = config('auth.defaults.guard');
        $providerName = is_string($guardName) && $guardName !== ''
            ? config("auth.guards.{$guardName}.provider")
            : null;
        $model = is_string($providerName) && $providerName !== ''
            ? config("auth.providers.{$providerName}.model")
            : null;

        if (is_string($model) && $model !== '') {
            return $model;
        }

        $provider = $this->userProvider();

        return $provider !== null && method_exists($provider, 'getModel') && is_string($provider->getModel())
            ? $provider->getModel()
            : null;
    }

    private function assertEditable(BeamUxRelease $release): void
    {
        if ($release->status === 'published') {
            $this->refuse(ReleaseError::PublishedImmutable);
        }
    }

    /** @param  array<string, scalar>  $params */
    private function refuse(\Splicewire\Beam\Localization\CodedError $error, array $params = []): never
    {
        throw \Splicewire\Beam\Localization\CodedValidationException::for('release', $error, $params);
    }
}
