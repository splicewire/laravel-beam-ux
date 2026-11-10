<?php

namespace Splicewire\Beam\Ux\Release\Kinds;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Workflows\Control\TransitionContext;

/**
 * One kind of Release member (ADR-0243 amending ADR-0226): what a Release needs to know about a subject it holds.
 *
 * The Release owns what every member shares: its row lock, its lifecycle, review, scheduling and Preview Links. A kind
 * owns its subject: how a `member_ref` resolves (and that it shares the Release's realm and connection), who may place
 * and publish it, whether it may join, whether it moved since it joined, and what Publish does to it. A member's
 * `version_id` is the version the Release publishes, or null to withdraw the subject; `observed_pin` and
 * `observed_marking` are what the kind saw of the subject when it joined.
 *
 * Every refusal is a `ValidationException` on `release`, or an `AuthorizationException`, as the Release's own are.
 *
 * @api
 */
interface ReleaseMemberKind
{
    /** The `member_kind` value, and this kind's key in {@see ReleaseMemberKindRegistry}. */
    public function kind(): string;

    /**
     * Lock this kind's subjects on the Release's connection, in one fixed order, before a transition reads them. Called
     * for every registered kind on every transition, members or not, so two Releases lock the same graph in the same
     * order.
     */
    public function lock(BeamUxRelease $release): void;

    /** The subject `$ref` addresses. Refuses one outside the Release's realm or connection. */
    public function subject(BeamUxRelease $release, string $ref): object;

    public function authorize(BeamUxRelease $release, object $subject, Authenticatable $actor, ReleaseAccess $access): void;

    /**
     * Whether `$member` (not yet saved: its `version_id` and any column of this kind's) may join the Release.
     */
    public function admit(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void;

    /**
     * The member columns this kind writes besides the release, kind, ref and version: what it observed of the subject
     * as it joined (`observed_pin`, `observed_marking`), and any column its own readers key on.
     *
     * @return array<string, mixed>
     */
    public function columns(object $subject): array;

    /** A stale member: the subject moved after it joined (ADR-0226 d6). Publish refuses it. */
    public function moved(object $subject, BeamUxReleaseMember $member): bool;

    /** The checks Publish makes once the member is current. Publish and the readiness report both run it. */
    public function assertPublishable(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void;

    /**
     * Publish this kind's members, inside the Release's publish transition, after every member of every kind passed.
     *
     * @param  Collection<int, BeamUxReleaseMember>  $members
     */
    public function publish(BeamUxRelease $release, Collection $members, Authenticatable $actor, TransitionContext $context): void;

    /** The version a rollback Release selects for this member: what it replaced, or null to withdraw the subject. */
    public function rollbackRef(BeamUxReleaseMember $member): ?string;
}
