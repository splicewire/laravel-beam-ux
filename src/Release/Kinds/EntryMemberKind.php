<?php

namespace Splicewire\Beam\Ux\Release\Kinds;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;
use Splicewire\Beam\Ux\Redirect\RedirectService;
use Splicewire\Beam\Ux\Redirect\ReleaseRedirects;
use Splicewire\Beam\Ux\Support\EditorAuthorizer;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;

/**
 * A page-editor entry as a Release member: the only kind before ADR-0243, lifted out of ReleaseService unchanged.
 * `member_ref` is the entry's id, which the member also carries as `entry_id`. A null version is a Take-down, and only a
 * Take-down carries a redirect target (ADR-0236 d4); both stay this kind's.
 *
 * @api
 */
class EntryMemberKind implements ReleaseMemberKind
{
    public function kind(): string
    {
        return BeamUxReleaseMember::KIND_ENTRY;
    }

    /** Lock the same final graph in the same order as EntryUrlChanges / batch publication. */
    public function lock(BeamUxRelease $release): void
    {
        $this->prototype($release)->newQuery()->orderBy('id')->lockForUpdate()->get();
    }

    /** A member's entry, which must share the Release's realm and connection. */
    public function subject(BeamUxRelease $release, string $ref): BeamUxEntry
    {
        $entry = BeamUxEntry::on($release->getConnectionName())->findOrFail($ref);
        if ($entry->realm !== $release->realm) {
            $this->refuse('Release members must belong to its realm and connection.');
        }

        return $entry;
    }

    public function authorize(BeamUxRelease $release, object $subject, Authenticatable $actor, ReleaseAccess $access): void
    {
        $access === ReleaseAccess::Direct
            ? $this->authorizeDirect($subject, $actor)
            : $this->authorizeEntry($subject, $actor, $access === ReleaseAccess::Publish);
    }

    public function admit(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void
    {
        $this->assertMember($subject, $member->version_id, $member->redirect_target, $this->publishing($release));
    }

    public function columns(object $subject): array
    {
        return ['entry_id' => $subject->id, 'observed_pin' => $subject->published_version, 'observed_marking' => $subject->workflow_marking];
    }

    /** A stale member: its entry's publication pin or marking moved after it joined (ADR-0226 d6). */
    public function moved(object $subject, BeamUxReleaseMember $member): bool
    {
        return $subject->published_version !== $member->observed_pin || $subject->workflow_marking !== $member->observed_marking;
    }

    public function assertPublishable(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void
    {
        $this->assertPublishableAgainst($release, $subject, $member, $this->removed($release));
    }

    /**
     * The member checks Publish makes once the member is current: its fixed version still reads and compiles, or its
     * Take-down is admissible and does not redirect to a page the same Release takes down. Publish and the readiness
     * report (ADR-0232 d11) both run it.
     *
     * @param  list<string>  $removed  the entries the Release takes down
     */
    public function assertPublishableAgainst(BeamUxRelease $release, BeamUxEntry $entry, BeamUxReleaseMember $member, array $removed): void
    {
        $this->assertMember($entry, $member->version_id, $member->redirect_target, $this->publishing($release));
        if ($member->redirect_target !== null) {
            foreach ($removed as $removedId) {
                $target = $this->subject($release, $removedId);
                if ($member->redirect_target === $target->id || $member->redirect_target === $target->url()) {
                    $this->refuse('A redirect target cannot be taken down in the same Release.');
                }
            }
            // ADR-0236 d4, EP-31 ruling 4: the target is live once the Release publishes, so not under a page it hides.
            $members = $release->members()->get();
            $planner = app(ReleaseRedirects::class);
            $prototype = $this->prototype($release);
            $target = $planner->targetOf($prototype, $member->redirect_target);
            $refs = $members->whereNotNull('version_id')->mapWithKeys(fn ($m) => [(string) $m->entry_id => (string) $m->version_id])->all();
            $takedowns = $members->whereNull('version_id')->mapWithKeys(fn ($m) => [(string) $m->entry_id => $m->redirect_target])->all();
            if ($target === null || ! $planner->liveAfter($prototype, $refs, $takedowns, $target)) {
                $this->refuse('The Take-down redirect target must be live once this Release publishes: published, and not under a page it hides.');
            }
        }
    }

    /**
     * Publish the entries: their versions (with the Release's recording), then their Take-downs, then the Redirects the
     * Release leaves (ADR-0236 d2, d4).
     */
    public function publish(BeamUxRelease $release, Collection $members, Authenticatable $actor, TransitionContext $context): void
    {
        $prototype = $this->prototype($release);
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
                $entry = $this->subject($release, $member->entry_id);
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
        $redirects = app(ReleaseRedirects::class)->plan($prototype, $refs,
            $members->whereNull('version_id')->mapWithKeys(fn ($member) => [(string) $member->entry_id => $member->redirect_target])->all());
        if ($refs !== []) {
            app(EntryPublication::class)->publishVersions($prototype, $refs, $actor instanceof Model ? $actor : null, $pins,
                fn (array $prepared) => app(ReleaseRecording::class)->prepare($release, $prepared, $actor, $takedowns),
                leaveRedirects: false);
        } elseif ($takedowns !== []) {
            app(ReleaseRecording::class)->prepare($release, [], $actor, $takedowns);
        }
        foreach ($members as $member) {
            if ($member->version_id === null) {
                $entry = $this->subject($release, $member->entry_id);
                $result = app(WorkflowActuator::class)->transition($entry, 'unpublish', clone $context);
                if (! $result->applied) {
                    $this->refuse('The entry lifecycle refused Take-down.');
                }
            }
        }
        app(RedirectService::class)->leave($redirects, 'release', (string) $release->getKey(),
            $actor instanceof Model ? $actor : null, $release->getConnectionName());
    }

    /** The pin the member replaced; a previously hidden managed entry is taken down, even if it retained a pin. */
    public function rollbackRef(BeamUxReleaseMember $member): ?string
    {
        if ($member->observed_marking !== null && $member->observed_marking !== BeamUxEntry::MARKING_PUBLISHED) {
            return null;
        }

        return $member->observed_pin;
    }

    /** Placing, dropping or reviewing an entry; `$publish` adds the publish ladder (approve, schedule, publish, record). */
    public function authorizeEntry(BeamUxEntry $entry, Authenticatable $actor, bool $publish): void
    {
        $gate = Gate::forUser($actor);
        app(EditorAuthorizer::class)->authorize($actor);

        foreach ($publish ? ['edit', 'publish'] : ['edit'] as $kind) {
            $ability = $this->entryAbility($entry, $kind);
            if ($ability !== null) {
                $gate->authorize($ability, $entry);
            }
        }
    }

    /** A direct Release's single check: `ux.author`, then the publish ladder alone. */
    public function authorizeDirect(BeamUxEntry $entry, Authenticatable $actor): void
    {
        $gate = Gate::forUser($actor);
        app(EditorAuthorizer::class)->authorize($actor);
        $ability = $this->entryAbility($entry, 'publish');
        if ($ability !== null) {
            $gate->authorize($ability, $entry);
        }
    }

    /** @return list<string> the entries the Release takes down */
    public function removed(BeamUxRelease $release): array
    {
        return $release->members()->whereNull('version_id')->pluck('entry_id')->map(fn ($id) => (string) $id)->all();
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
                    // A Take-down redirects to a page (ADR-0236 d4), never a component, layout or theme.
                    return $candidate->id !== $entry->id && $candidate->realm === $entry->realm && $candidate->type === UxType::Page
                        && (! $workflow->manages($candidate) || $candidate->workflow_marking === BeamUxEntry::MARKING_PUBLISHED
                            || in_array((string) $candidate->id, $publishing, true))
                        && ($candidate->id === $redirectTarget || $candidate->url() === $redirectTarget);
                });
            if ($target === null) {
                $this->refuse('The Take-down redirect must resolve to a published page in this realm, or one this Release publishes.');
            }
        }
        // Admission only: the Release publishes the Redirects (ADR-0236 d4), once assertPublishable() finds the target
        // live after it.
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

    private function prototype(BeamUxRelease $release): BeamUxEntry
    {
        return (new BeamUxEntry)->setConnection($release->getConnectionName());
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['release' => $message]);
    }
}
