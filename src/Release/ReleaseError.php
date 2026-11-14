<?php

namespace Splicewire\Beam\Ux\Release;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a Release, a Release member or a Take-down was refused, and the readiness checklist's own words (localization 23;
 * ticket 13's pattern). The English is byte-identical to the literal it replaces. `:blockers` is the workflow's own list,
 * passed through as a parameter.
 */
enum ReleaseError: string implements CodedError
{
    use IsCodedError;

    #[Message('A Release needs a title of at most 255 characters.')]
    case TitleTooLong = 'release_title_too_long';

    #[Message('Turn Preview Links on before rotating them.')]
    case PreviewLinksOff = 'release_preview_links_off';

    #[Message('The entry has no saved version.')]
    case EntryNoSavedVersion = 'release_entry_no_saved_version';

    #[Message('The Release workflow refused :transition: :blockers')]
    case WorkflowRefused = 'release_workflow_refused';

    #[Message('Only a published Release can produce a rollback Release.')]
    case RollbackNeedsPublished = 'release_rollback_needs_published';

    #[Message('The Release has no members to roll back.')]
    case RollbackNoMembers = 'release_rollback_no_members';

    #[Message('A Release with no page members cannot be rolled back yet.')]
    case RollbackNoPages = 'release_rollback_no_pages';

    #[Message('Scheduled publication requires the calendars ActionService. Publish now is available without it.')]
    case SchedulingNeedsCalendars = 'release_scheduling_needs_calendars';

    #[Message('An empty Release cannot advance.')]
    case Empty = 'release_empty';

    #[Message('A publication pin or marking moved. Rebase or drop that member.')]
    case PinOrMarkingMoved = 'release_pin_or_marking_moved';

    #[Message('Schedule this Release through the calendars action service.')]
    case ScheduleThroughCalendars = 'release_schedule_through_calendars';

    #[Message('This Release requires approval before publication.')]
    case ApprovalRequired = 'release_approval_required';

    #[Message("The `entry` Release member kind must be beam-ux's EntryMemberKind.")]
    case EntryKindMismatch = 'release_entry_kind_mismatch';

    #[Message('A published Release is immutable; create a rollback Release instead.')]
    case PublishedImmutable = 'release_published_immutable';

    #[Message('Release members must belong to its realm and connection.')]
    case MembersOutsideRealm = 'release_members_outside_realm';

    #[Message('A redirect target cannot be taken down in the same Release.')]
    case RedirectTargetTakenDown = 'release_redirect_target_taken_down';

    #[Message('The Take-down redirect target must be live once this Release publishes: published, and not under a page it hides.')]
    case TakeDownTargetNotLive = 'release_take_down_target_not_live';

    #[Message('A Take-down requires the currently published body snapshot.')]
    case TakeDownNeedsSnapshot = 'release_take_down_needs_snapshot';

    #[Message('The entry lifecycle refused Take-down.')]
    case TakeDownRefused = 'release_take_down_refused';

    #[Message('Only a Take-down carries a redirect target.')]
    case RedirectOnlyOnTakeDown = 'release_redirect_only_on_take_down';

    #[Message('The selected saved version does not belong to this entry.')]
    case VersionNotOfEntry = 'release_version_not_of_entry';

    #[Message('Take-down requires an entry lifecycle that currently permits unpublish.')]
    case TakeDownLifecycle = 'release_take_down_lifecycle';

    #[Message('The Take-down redirect must resolve to a published page in this realm, or one this Release publishes.')]
    case TakeDownRedirectUnresolved = 'release_take_down_redirect_unresolved';

    #[Message('Take-down is not available from this entry state.')]
    case TakeDownUnavailable = 'release_take_down_unavailable';

    #[Message('A Release entry no longer exists.')]
    case EntryMissing = 'release_entry_missing';

    #[Message('A publication pin moved. Rebase or drop that Release member before publishing.')]
    case PinMoved = 'release_pin_moved';

    #[Message('This Release holds a member of kind `:kind`, which no installed package provides.')]
    case MemberKindUnknown = 'release_member_kind_unknown';

    #[Message('Publication conflicted with another local write. Nothing was published. Reload and explicitly retry.')]
    case PublicationConflicted = 'release_publication_conflicted';

    #[Message('Scheduled publication must use the current author principal.')]
    case ScheduleAuthorPrincipal = 'release_schedule_author_principal';

    #[Message('The scheduled Release membership changed; schedule the reviewed revision again.')]
    case ScheduleMembershipChanged = 'release_schedule_membership_changed';

    #[Message('A different author must review this Release.')]
    case DifferentReviewer = 'release_different_reviewer';

    #[Message('A Release requires a currently resolvable author.')]
    case AuthorUnresolvable = 'release_author_unresolvable';

    #[Message('A tenant actor requires an initialized tenant identity.')]
    case TenantIdentityRequired = 'release_tenant_identity_required';

    #[Message('Only a published nonempty Release can be recorded.')]
    case RecordNeedsPublished = 'release_record_needs_published';

    #[Message('This page stays hidden after Publish: a page above it is hidden.')]
    case StaysHidden = 'release_stays_hidden';
}
