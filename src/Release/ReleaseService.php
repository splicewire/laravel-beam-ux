<?php

namespace Splicewire\Beam\Ux\Release;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\TransitionResult;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;

/** ADR-0226: all writes are on the Release's connection and serialized by its row lock. */
final class ReleaseService
{
    /** ADR-0226 d1: the editor and Workflow tab use the same Release admission as batches. */
    public function requestOne(BeamUxEntry $entry, ?string $ref, Authenticatable $actor): BeamUxRelease
    {
        return $entry->getConnection()->transaction(function () use ($entry, $ref, $actor) {
            $release = $this->create($entry, ($ref === null ? 'Take down: ' : 'Publish: ').$entry->slug, $actor);
            $this->putMember($release, $entry->id, $ref, $actor);
            $this->transition($release, config('beam.ux.releases.review_required', false) ? 'submit' : 'publish', $actor);

            return $release->fresh();
        });
    }

    public function create(BeamUxEntry $context, string $title, Authenticatable $actor): BeamUxRelease
    {
        $this->authorizeEntry($context, $actor, false);
        if (trim($title) === '' || mb_strlen($title) > 255) {
            $this->refuse('A Release needs a title of at most 255 characters.');
        }
        $release = new BeamUxRelease;
        $release->setConnection($context->getConnectionName());
        app(\Splicewire\Beam\Workflows\Definition\DefinitionStore::class)->onConnection($context->getConnectionName())
            ->ensureSystemLineage(ReleaseLifecycle::DEFINITION, 'UX Release', ReleaseLifecycle::blueprint());
        $release->forceFill(['title' => $title, 'realm' => $context->realm, 'created_by' => $this->token($actor), 'edited_by' => $this->token($actor), 'status' => 'draft'])->save();

        return $release;
    }

    public function putMember(BeamUxRelease $release, string $entryId, ?string $ref, Authenticatable $actor, ?string $redirectTarget = null): void
    {
        $release->getConnection()->transaction(function () use ($release, $entryId, $ref, $actor, $redirectTarget) {
            $locked = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $this->assertEditable($locked);
            $entry = $this->entry($locked, $entryId);
            $this->authorizeEntry($entry, $actor, false);
            $this->assertMember($entry, $ref, $redirectTarget);
            $this->transition($locked, 'revise', $actor);
            $member = $locked->members()->where('entry_id', $entryId)->first()
                ?? (new \Splicewire\Beam\Ux\Models\BeamUxReleaseMember)->setConnection($locked->getConnectionName());
            $member->forceFill([
                'release_id' => $locked->id, 'entry_id' => $entryId, 'version_id' => $ref,
                'observed_pin' => $entry->published_version, 'observed_marking' => $entry->workflow_marking,
                'redirect_target' => $redirectTarget,
            ])->save();
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
            $this->authorizeEntry($entry, $actor, in_array($transition, ['approve', 'schedule', 'publish'], true));
            if ($transition === 'publish') {
                if ($entry->published_version !== $member->observed_pin || $entry->workflow_marking !== $member->observed_marking) {
                    $this->refuse('A publication pin or marking moved. Rebase or drop that member.');
                }
                $this->assertMember($entry, $member->version_id, $member->redirect_target);
                if ($member->redirect_target !== null) {
                    foreach ($removed as $removedId) {
                        $target = $this->entry($release, $removedId);
                        if ($member->redirect_target === $target->id || $member->redirect_target === $target->url()) {
                            $this->refuse('A redirect target cannot be taken down in the same Release.');
                        }
                    }
                }
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
            if (config('beam.ux.releases.review_required', false) && (! in_array($release->status, ['approved', 'scheduled'], true) || $release->reviewed_by === null)) {
                $this->refuse('This Release requires approval before publication.');
            }
            $refs = $pins = [];
            foreach ($members as $member) {
                if ($member->version_id !== null) {
                    $refs[$member->entry_id] = $member->version_id;
                    $pins[$member->entry_id] = $member->observed_pin;
                }
            }
            if ($refs !== []) {
                app(EntryPublication::class)->publishVersions($prototype, $refs, $actor instanceof Model ? $actor : null, $pins);
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
        }
    }

    private function assertMember(BeamUxEntry $entry, ?string $ref, ?string $redirectTarget): void
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
            $target = $entry->newQuery()->whereNotNull('published_version')->get()->first(function (BeamUxEntry $candidate) use ($entry, $redirectTarget, $workflow) {
                return $candidate->id !== $entry->id && $candidate->realm === $entry->realm
                    && (! $workflow->manages($candidate) || $candidate->workflow_marking === BeamUxEntry::MARKING_PUBLISHED)
                    && ($candidate->id === $redirectTarget || $candidate->url() === $redirectTarget);
            });
            if ($target === null) {
                $this->refuse('The Take-down redirect must resolve to a published entry in this realm.');
            }
        }
        // Admission only. EP-31 owns redirect emission.
    }

    private function entry(BeamUxRelease $release, string $id): BeamUxEntry
    {
        $entry = BeamUxEntry::on($release->getConnectionName())->findOrFail($id);
        if ($entry->realm !== $release->realm) {
            $this->refuse('Release members must belong to its realm and connection.');
        }

        return $entry;
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
            ? Auth::createUserProvider()?->retrieveById(substr($token, 5)) : null;
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException('A Release requires a currently resolvable author.');
        }

        return $actor;
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
