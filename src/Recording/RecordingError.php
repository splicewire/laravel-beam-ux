<?php

namespace Splicewire\Beam\Ux\Recording;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a RecordingFailure was raised (localization 23, the laravel-beam-ux sweep: the recording/file commit; coord-lead ruling (a),
 * 23:32Z). Each case's English is byte-identical to the literal it replaces, and its value is the stable `code` a client
 * receives. The exception keeps the raw English as its own message (logs; ThemeReconciliation STORES it, which is
 * 37's); only the response emit renders the localized message. Generated from the RecordingFailure sites, one case per
 * distinct message, named by its reason and leading words.
 */
enum RecordingError: string implements CodedError
{
    use IsCodedError;

    #[Message('The Release mirror manifest is too large or contains duplicate paths.')]
    case ManifestReleaseMirrorManifestTooLargeOr = 'recording_manifest_release_mirror_manifest_too_large_or';

    #[Message('A moved page\'s old mirror differs from its published bytes.')]
    case MirrorDriftMovedPagesOldMirrorDiffersFrom = 'recording_mirror_drift_moved_pages_old_mirror_differs_from';

    #[Message('A Take-down mirror differs from the currently published bytes.')]
    case MirrorDriftTakeDownMirrorDiffersFromCurrently = 'recording_mirror_drift_take_down_mirror_differs_from_currently';

    #[Message('No durable recording receipt is available.')]
    case UnavailableNoDurableRecordingReceiptAvailable = 'recording_unavailable_no_durable_recording_receipt_available';

    #[Message('A newer publication superseded this mirror completion.')]
    case SupersededNewerPublicationSupersededMirrorCompletion = 'recording_superseded_newer_publication_superseded_mirror_completion';

    #[Message('A newer publication superseded this Take-down completion.')]
    case SupersededNewerPublicationSupersededTakeDownCompletion = 'recording_superseded_newer_publication_superseded_take_down_completion';

    #[Message('No mirror disk is enabled.')]
    case MirrorDisabledNoMirrorDiskEnabled = 'recording_mirror_disabled_no_mirror_disk_enabled';

    #[Message('Take-down recording needs an enabled local mirror disk.')]
    case MirrorDisabledTakeDownRecordingNeedsEnabledLocal = 'recording_mirror_disabled_take_down_recording_needs_enabled_local';

    #[Message('A Release mirror could not be completed.')]
    case MirrorReleaseMirrorCouldNotCompleted = 'recording_mirror_release_mirror_could_not_completed';

    #[Message('A Release mirror did not match its published snapshot.')]
    case MirrorReleaseMirrorDidNotMatchPublished = 'recording_mirror_release_mirror_did_not_match_published';

    #[Message('The Take-down is missing its frozen mirror receipt.')]
    case ManifestTakeDownMissingFrozenMirrorReceipt = 'recording_manifest_take_down_missing_frozen_mirror_receipt';

    #[Message('A Take-down mirror differs from the frozen published bytes.')]
    case MirrorDriftTakeDownMirrorDiffersFromFrozen = 'recording_mirror_drift_take_down_mirror_differs_from_frozen';

    #[Message('A Take-down mirror could not be removed.')]
    case MirrorTakeDownMirrorCouldNotRemoved = 'recording_mirror_take_down_mirror_could_not_removed';

    #[Message('A moved page\'s old mirror differs from the frozen published bytes.')]
    case MirrorDriftMovedPagesOldMirrorDiffersFrom2 = 'recording_mirror_drift_moved_pages_old_mirror_differs_from2';

    #[Message('A moved page\'s old mirror could not be removed.')]
    case MirrorMovedPagesOldMirrorCouldNot = 'recording_mirror_moved_pages_old_mirror_could_not';

    #[Message('This Release has no supported frozen recording receipt.')]
    case UnavailableReleaseHasNoSupportedFrozenRecording = 'recording_unavailable_release_has_no_supported_frozen_recording';

    #[Message('The mirror disk no longer matches this frozen receipt.')]
    case PathMirrorDiskNoLongerMatchesFrozen = 'recording_path_mirror_disk_no_longer_matches_frozen';

    #[Message('The tenancy context cannot be verified.')]
    case TenancyTenancyContextCannotVerified = 'recording_tenancy_tenancy_context_cannot_verified';

    #[Message('Local recording is unavailable while tenancy is active.')]
    case TenancyLocalRecordingUnavailableWhileTenancyActive = 'recording_tenancy_local_recording_unavailable_while_tenancy_active';

    #[Message('The Release is not proven central-resident.')]
    case TenancyReleaseNotProvenCentralResident = 'recording_tenancy_release_not_proven_central_resident';

    #[Message('Every Release member must be proven central-resident.')]
    case TenancyEveryReleaseMemberMustProvenCentral = 'recording_tenancy_every_release_member_must_proven_central';

    #[Message('The saved recording belongs to a different repository or branch.')]
    case RecoveryRequiredSavedRecordingBelongsDifferentRepositoryOr = 'recording_recovery_required_saved_recording_belongs_different_repository_or';

    #[Message('Release membership or mirror placement no longer matches its frozen receipt.')]
    case ManifestReleaseMembershipOrMirrorPlacementNo = 'recording_manifest_release_membership_or_mirror_placement_no';

    #[Message('The Git worktree file differs from the published Take-down bytes.')]
    case MirrorDriftGitWorktreeFileDiffersFromPublished = 'recording_mirror_drift_git_worktree_file_differs_from_published';

    #[Message('The saved no-change Git result is no longer reachable.')]
    case RecoveryRequiredSavedNoChangeGitResultNo = 'recording_recovery_required_saved_no_change_git_result_no';

    #[Message('The recorded Git result is no longer reachable.')]
    case RecoveryRequiredRecordedGitResultNoLongerReachable = 'recording_recovery_required_recorded_git_result_no_longer_reachable';

    #[Message('A Take-down path reappeared after its frozen deletion.')]
    case MirrorDriftTakeDownPathReappearedAfterFrozen = 'recording_mirror_drift_take_down_path_reappeared_after_frozen';

    #[Message('An absent Take-down path is not proven to have been removed by this Release.')]
    case MirrorDriftAbsentTakeDownPathNotProven = 'recording_mirror_drift_absent_take_down_path_not_proven';

    #[Message('A file differs from this Release. Resolve the file explicitly before recording.')]
    case MirrorDriftFileDiffersFromReleaseResolveFile = 'recording_mirror_drift_file_differs_from_release_resolve_file';

    #[Message('An unsaved or missing Git preparation needs explicit recovery; no second commit was attempted.')]
    case RecoveryRequiredUnsavedOrMissingGitPreparationNeeds = 'recording_recovery_required_unsaved_or_missing_git_preparation_needs';

    #[Message('Installation started but the saved candidate is not reachable. No second installation was attempted.')]
    case RecoveryRequiredInstallationStartedButSavedCandidateNot = 'recording_recovery_required_installation_started_but_saved_candidate_not';

    #[Message('The branch moved without the saved recording. No second commit was attempted.')]
    case RecoveryRequiredBranchMovedWithoutSavedRecordingNo = 'recording_recovery_required_branch_moved_without_saved_recording_no';

    #[Message('Local recording needs an enabled local mirror disk.')]
    case MirrorDisabledLocalRecordingNeedsEnabledLocalMirror = 'recording_mirror_disabled_local_recording_needs_enabled_local_mirror';

    #[Message('The configured mirror root must be absolute.')]
    case PathConfiguredMirrorRootMustAbsolute = 'recording_path_configured_mirror_root_must_absolute';

    #[Message('The configured mirror root cannot be resolved.')]
    case PathConfiguredMirrorRootCannotResolved = 'recording_path_configured_mirror_root_cannot_resolved';

    #[Message('A resolved mirror path leaves its configured root.')]
    case PathResolvedMirrorPathLeavesConfiguredRoot = 'recording_path_resolved_mirror_path_leaves_configured_root';

    #[Message('The mirror root must be a canonical local directory.')]
    case PathMirrorRootMustCanonicalLocalDirectory = 'recording_path_mirror_root_must_canonical_local_directory';

    #[Message('Symlink mirror roots cannot be recorded.')]
    case PathSymlinkMirrorRootsCannotRecorded = 'recording_path_symlink_mirror_roots_cannot_recorded';

    #[Message('Symlink mirror paths cannot be recorded.')]
    case PathSymlinkMirrorPathsCannotRecorded = 'recording_path_symlink_mirror_paths_cannot_recorded';

    #[Message('Nested Git worktrees cannot be mirror members.')]
    case PathNestedGitWorktreesCannotMirrorMembers = 'recording_path_nested_git_worktrees_cannot_mirror_members';

    #[Message('The mirror path is not a contained regular file.')]
    case PathMirrorPathNotContainedRegularFile = 'recording_path_mirror_path_not_contained_regular_file';

    #[Message('Recording needs the original attached local branch.')]
    case RepositoryStateRecordingNeedsOriginalAttachedLocalBranch = 'recording_repository_state_recording_needs_original_attached_local_branch';

    #[Message('Finish the current Git operation before recording.')]
    case RepositoryStateFinishCurrentGitOperationBeforeRecording = 'recording_repository_state_finish_current_git_operation_before_recording';

    #[Message('This Git worktree configuration is not supported for recording.')]
    case UnsupportedConfigGitWorktreeConfigurationNotSupportedFor = 'recording_unsupported_config_git_worktree_configuration_not_supported_for';

    #[Message('Split indexes are not supported for recording.')]
    case UnsupportedIndexSplitIndexesNotSupportedForRecording = 'recording_unsupported_index_split_indexes_not_supported_for_recording';

    #[Message('The local recording lock is unavailable.')]
    case LockLocalRecordingLockUnavailable = 'recording_lock_local_recording_lock_unavailable';

    #[Message('A Release mirror path is not a contained regular file.')]
    case PathReleaseMirrorPathNotContainedRegular = 'recording_path_release_mirror_path_not_contained_regular';

    #[Message('The mirror path spelling cannot be verified.')]
    case PathMirrorPathSpellingCannotVerified = 'recording_path_mirror_path_spelling_cannot_verified';

    #[Message('Nested Git worktrees cannot be recorded as member files.')]
    case PathNestedGitWorktreesCannotRecordedAs = 'recording_path_nested_git_worktrees_cannot_recorded_as';

    #[Message('Symlink or non-directory mirror ancestors cannot be recorded.')]
    case PathSymlinkOrNonDirectoryMirrorAncestors = 'recording_path_symlink_or_non_directory_mirror_ancestors';

    #[Message('Only regular mirror files can be recorded.')]
    case PathOnlyRegularMirrorFilesCanRecorded = 'recording_path_only_regular_mirror_files_can_recorded';

    #[Message('Submodule mirrors cannot be recorded.')]
    case PathSubmoduleMirrorsCannotRecorded = 'recording_path_submodule_mirrors_cannot_recorded';

    #[Message('Git ignores this mirror path, so it is not recorded.')]
    case MirrorIgnoredGitIgnoresMirrorPathSoNot = 'recording_mirror_ignored_git_ignores_mirror_path_so_not';

    #[Message('Git ignore rules cannot be verified.')]
    case RepositoryStateGitIgnoreRulesCannotVerified = 'recording_repository_state_git_ignore_rules_cannot_verified';

    #[Message('All Release mirrors must belong to one Git worktree.')]
    case PathAllReleaseMirrorsMustBelongOne = 'recording_path_all_release_mirrors_must_belong_one';

    #[Message('The frozen recording manifest is invalid.')]
    case ManifestFrozenRecordingManifestInvalid = 'recording_manifest_frozen_recording_manifest_invalid';

    #[Message('More than one Git candidate claims this recording identity.')]
    case RecoveryRequiredMoreThanOneGitCandidateClaims = 'recording_recovery_required_more_than_one_git_candidate_claims';

    #[Message('The saved Git candidate does not match this recording.')]
    case RecoveryRequiredSavedGitCandidateDoesNotMatch = 'recording_recovery_required_saved_git_candidate_does_not_match';

    #[Message('A Git hook changed files outside this Release.')]
    case RecoveryRequiredGitHookChangedFilesOutsideRelease = 'recording_recovery_required_git_hook_changed_files_outside_release';

    #[Message('A Git hook restored a frozen Take-down member.')]
    case RecoveryRequiredGitHookRestoredFrozenTakeDown = 'recording_recovery_required_git_hook_restored_frozen_take_down';

    #[Message('A Git hook changed a frozen Release member.')]
    case RecoveryRequiredGitHookChangedFrozenReleaseMember = 'recording_recovery_required_git_hook_changed_frozen_release_member';

    #[Message('The recorded Git result cannot be verified.')]
    case RecoveryRequiredRecordedGitResultCannotVerified = 'recording_recovery_required_recorded_git_result_cannot_verified';

    #[Message('Local recording preparation is unavailable.')]
    case PreparationLocalRecordingPreparationUnavailable = 'recording_preparation_local_recording_preparation_unavailable';

    #[Message('A Release mirror path has a case-colliding file or ancestor.')]
    case PathReleaseMirrorPathHasCaseColliding = 'recording_path_release_mirror_path_has_case_colliding';

    #[Message('Git configuration cannot be verified.')]
    case RepositoryStateGitConfigurationCannotVerified = 'recording_repository_state_git_configuration_cannot_verified';

    /** `:detail` is the Git diagnostic class and exit status, machine context passed through. */
    #[Message('Local Git recording failed (:detail).')]
    case GitFailedLocalGitRecordingFailed = 'recording_git_failed_local_git_recording_failed';

    // Hand-keyed (not generated): the recording op's own refusals and the files-recording read policy.

    #[Message('The selected Release must contain this addressed file.')]
    case ReleaseMustContainFile = 'recording_release_must_contain_file';

    #[Message('The local recording did not complete.')]
    case DidNotComplete = 'recording_did_not_complete';

    /** The recording's status line (already keyed by ReleaseRecordingData), passed through with its code. */
    #[Message(':detail')]
    case NotRecorded = 'recording_not_recorded';

    #[Message('Files recording requires an addressed release member.')]
    case AddressedMemberRequired = 'recording_addressed_member_required';
}
