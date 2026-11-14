<?php

namespace Splicewire\Beam\Ux\Files;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a FileConflict was raised (localization 23, the laravel-beam-ux sweep: the recording/file commit; coord-lead ruling (a),
 * 23:32Z). Each case's English is byte-identical to the literal it replaces, and its value is the stable `code` a client
 * receives. The exception keeps the raw English as its own message (logs; ThemeReconciliation STORES it, which is
 * 37's); only the response emit renders the localized message. Generated from the FileConflict sites, one case per
 * distinct message, named by its reason and leading words.
 */
enum FileError: string implements CodedError
{
    use IsCodedError;

    #[Message('Only the MDX content may change. Preserve the exact published title and navOrder header.')]
    case InvalidOnlyMdxContentMayChangePreserve = 'file_invalid_only_mdx_content_may_change_preserve';

    #[Message('The complete published MDX body cannot be verified.')]
    case UnsupportedCompletePublishedMdxBodyCannotVerified = 'file_unsupported_complete_published_mdx_body_cannot_verified';

    #[Message('MDX import requires bounded UTF-8 with a complete canonical LF header.')]
    case InvalidMdxImportRequiresBoundedUtf8 = 'file_invalid_mdx_import_requires_bounded_utf8';

    #[Message('MDX header normalization is not imported. Preserve the exact canonical title and navOrder header.')]
    case InvalidMdxHeaderNormalizationNotImportedPreserve = 'file_invalid_mdx_header_normalization_not_imported_preserve';

    #[Message('The MDX body is not a lossless stable representation.')]
    case InvalidMdxBodyNotLosslessStableRepresentation = 'file_invalid_mdx_body_not_lossless_stable_representation';

    #[Message('A complete canonical MDX header is required.')]
    case InvalidCompleteCanonicalMdxHeaderRequired = 'file_invalid_complete_canonical_mdx_header_required';

    #[Message('MDX requires exactly a complete frontmatter/content body and nonblank content.')]
    case InvalidMdxRequiresExactlyCompleteFrontmatterContent = 'file_invalid_mdx_requires_exactly_complete_frontmatter_content';

    #[Message('MDX import supports only a plain title and canonical nonnegative navOrder, both unchanged from publication.')]
    case InvalidMdxImportSupportsOnlyPlainTitle = 'file_invalid_mdx_import_supports_only_plain_title';

    #[Message('File reconciliation needs an enabled local mirror disk.')]
    case UnsupportedFileReconciliationNeedsEnabledLocalMirror = 'file_unsupported_file_reconciliation_needs_enabled_local_mirror';

    #[Message('The local mirror path cannot be verified.')]
    case UnsupportedLocalMirrorPathCannotVerified = 'file_unsupported_local_mirror_path_cannot_verified';

    #[Message('The local mirror path is not contained.')]
    case UnsupportedLocalMirrorPathNotContained = 'file_unsupported_local_mirror_path_not_contained';

    #[Message('The local mirror path cannot be read.')]
    case UnsupportedLocalMirrorPathCannotRead = 'file_unsupported_local_mirror_path_cannot_read';

    #[Message('A local mirror path has a case alias.')]
    case UnsupportedLocalMirrorPathHasCaseAlias = 'file_unsupported_local_mirror_path_has_case_alias';

    #[Message('Symlink and non-directory mirror ancestors are unsupported.')]
    case UnsupportedSymlinkAndNonDirectoryMirrorAncestors = 'file_unsupported_symlink_and_non_directory_mirror_ancestors';

    #[Message('Nested repositories and submodule mirrors are unsupported.')]
    case UnsupportedNestedRepositoriesAndSubmoduleMirrorsUnsupported = 'file_unsupported_nested_repositories_and_submodule_mirrors_unsupported';

    #[Message('Only regular local mirror files are supported.')]
    case UnsupportedOnlyRegularLocalMirrorFilesSupported = 'file_unsupported_only_regular_local_mirror_files_supported';

    #[Message('Submodule placement cannot be verified.')]
    case UnsupportedSubmodulePlacementCannotVerified = 'file_unsupported_submodule_placement_cannot_verified';

    #[Message('Submodule mirrors are unsupported.')]
    case UnsupportedSubmoduleMirrorsUnsupported = 'file_unsupported_submodule_mirrors_unsupported';

    #[Message('Symlink mirrors are unsupported.')]
    case UnsupportedSymlinkMirrorsUnsupported = 'file_unsupported_symlink_mirrors_unsupported';

    #[Message('The local mirror cannot be read.')]
    case InvalidLocalMirrorCannotRead = 'file_invalid_local_mirror_cannot_read';

    #[Message('The local mirror is not a bounded readable regular file.')]
    case InvalidLocalMirrorNotBoundedReadableRegular = 'file_invalid_local_mirror_not_bounded_readable_regular';

    #[Message('The mirror directory could not be created. Nothing was replaced.')]
    case IncompleteMirrorDirectoryCouldNotCreatedNothing = 'file_incomplete_mirror_directory_could_not_created_nothing';

    #[Message('A local file appeared or could not be created. Nothing was overwritten. Reload comparison.')]
    case FileMovedLocalFileAppearedOrCouldNot = 'file_file_moved_local_file_appeared_or_could_not';

    #[Message('Restore could not complete. The created file is preserved; inspect or move it before trying again.')]
    case IncompleteRestoreCouldNotCompleteCreatedFile = 'file_incomplete_restore_could_not_complete_created_file';

    #[Message('Restore could not confirm durable bytes. The created file is preserved; inspect it before retrying.')]
    case IncompleteRestoreCouldNotConfirmDurableBytes = 'file_incomplete_restore_could_not_confirm_durable_bytes';

    #[Message('The created file cannot be verified. Preserve it and inspect or move it before retrying.')]
    case IncompleteCreatedFileCannotVerifiedPreserveAnd = 'file_incomplete_created_file_cannot_verified_preserve_and';

    #[Message('Restore result changed or could not be verified. Preserve the local file and inspect before retrying.')]
    case IncompleteRestoreResultChangedOrCouldNot = 'file_incomplete_restore_result_changed_or_could_not';

    #[Message('The local file changed before Re-mirror. Nothing was overwritten. Reload comparison.')]
    case FileMovedLocalFileChangedBeforeReMirror = 'file_file_moved_local_file_changed_before_re_mirror';

    #[Message('A temporary mirror file could not be created. Nothing was overwritten.')]
    case IncompleteTemporaryMirrorFileCouldNotCreated = 'file_incomplete_temporary_mirror_file_could_not_created';

    #[Message('Re-mirror could not complete its temporary file. The original is unchanged.')]
    case IncompleteReMirrorCouldNotCompleteTemporary = 'file_incomplete_re_mirror_could_not_complete_temporary';

    #[Message('Re-mirror could not confirm durable bytes. The original is unchanged.')]
    case IncompleteReMirrorCouldNotConfirmDurable = 'file_incomplete_re_mirror_could_not_confirm_durable';

    #[Message('Re-mirror could not preserve the local file mode. The original is unchanged.')]
    case IncompleteReMirrorCouldNotPreserveLocal = 'file_incomplete_re_mirror_could_not_preserve_local';

    #[Message('The verified replacement could not be installed. The original is preserved.')]
    case IncompleteVerifiedReplacementCouldNotInstalledOriginal = 'file_incomplete_verified_replacement_could_not_installed_original';

    #[Message('The Re-mirror result could not be verified. Inspect the file before retrying.')]
    case IncompleteReMirrorResultCouldNotVerified = 'file_incomplete_re_mirror_result_could_not_verified';

    #[Message('The mirror directory changed. Nothing was overwritten.')]
    case PlacementMovedMirrorDirectoryChangedNothingWasOverwritten = 'file_placement_moved_mirror_directory_changed_nothing_was_overwritten';

    #[Message('An MDX page file begins with its page settings as a frontmatter header (ADR-0233 d3).')]
    case InvalidMdxPageFileBeginsWithPage = 'file_invalid_mdx_page_file_begins_with_page';

    #[Message('Theme source must be bounded UTF-8 text.')]
    case InvalidThemeSourceMustBoundedUtf8 = 'file_invalid_theme_source_must_bounded_utf8';

    #[Message('Use the exact generated Theme token format; malformed, duplicate or unrepresented source cannot be imported.')]
    case InvalidUseExactGeneratedThemeTokenFormat = 'file_invalid_use_exact_generated_theme_token_format';

    #[Message('This is a valid empty Theme representation. Clearing tokens is outside this key-preserving import.')]
    case ValidEmptyOutsideImportValidEmptyThemeRepresentationClearingTokens = 'file_valid_empty_outside_import_valid_empty_theme_representation_clearing_tokens';

    #[Message('Import must preserve every published token and namespace.')]
    case InvalidImportMustPreserveEveryPublishedToken = 'file_invalid_import_must_preserve_every_published_token';

    #[Message('Only existing Theme color leaves can change; other leaves must stay unchanged.')]
    case UnsupportedOnlyExistingThemeColorLeavesCan = 'file_unsupported_only_existing_theme_color_leaves_can';

    #[Message('Changed Theme colors must use a supported hexadecimal color.')]
    case InvalidChangedThemeColorsMustUseSupported = 'file_invalid_changed_theme_colors_must_use_supported';

    #[Message('The complete Theme body does not satisfy its declared schema.')]
    case InvalidCompleteThemeBodyDoesNotSatisfy = 'file_invalid_complete_theme_body_does_not_satisfy';

    #[Message('The Theme source could not be decoded and validated.')]
    case InvalidThemeSourceCouldNotDecodedAnd = 'file_invalid_theme_source_could_not_decoded_and';

    #[Message('The local file is missing. Nothing was imported.')]
    case FileMovedLocalFileMissingNothingWasImported = 'file_file_moved_local_file_missing_nothing_was_imported';

    #[Message('The local file changed during validation. Nothing was imported. Reload comparison.')]
    case FileMovedLocalFileChangedDuringValidationNothing = 'file_file_moved_local_file_changed_during_validation_nothing';

    #[Message('A different local file exists. Preserve or move it before restoring a missing published file. Nothing was overwritten.')]
    case FileExistsDifferentLocalFileExistsPreserveOr = 'file_file_exists_different_local_file_exists_preserve_or';

    #[Message('The local file is missing. Use Restore after a fresh comparison.')]
    case FileMovedLocalFileMissingUseRestoreAfter = 'file_file_moved_local_file_missing_use_restore_after';

    #[Message('The local file changed during validation. Nothing was overwritten. Reload comparison.')]
    case FileMovedLocalFileChangedDuringValidationNothing2 = 'file_file_moved_local_file_changed_during_validation_nothing2';

    #[Message('Explicit confirmation of the inspected batch is required.')]
    case ConfirmationExplicitConfirmationInspectedBatchRequired = 'file_confirmation_explicit_confirmation_inspected_batch_required';

    #[Message('The Files changed since the batch comparison. Nothing was taken. Reload comparison.')]
    case FileMovedFilesChangedSinceBatchComparisonNothing = 'file_file_moved_files_changed_since_batch_comparison_nothing';

    #[Message('A Release member changed since comparison. Nothing was taken.')]
    case MemberMovedReleaseMemberChangedSinceComparisonNothing = 'file_member_moved_release_member_changed_since_comparison_nothing';

    #[Message('A local file is missing. Nothing was taken. Reload comparison.')]
    case FileMovedLocalFileMissingNothingWasTaken = 'file_file_moved_local_file_missing_nothing_was_taken';

    #[Message('The Files changed during batch validation. Nothing was taken. Reload comparison.')]
    case FileMovedFilesChangedDuringBatchValidationNothing = 'file_file_moved_files_changed_during_batch_validation_nothing';

    #[Message('A local file changed during batch validation. Nothing was taken. Reload comparison.')]
    case FileMovedLocalFileChangedDuringBatchValidation = 'file_file_moved_local_file_changed_during_batch_validation';

    #[Message('Only canonical Theme/CSS, body-only Page/MDX, block-body Page/MDX, or block-body Page/TSX with its native codec is supported here.')]
    case UnsupportedOnlyCanonicalThemeCssBodyOnly = 'file_unsupported_only_canonical_theme_css_body_only';

    #[Message('A valid existing working head and published pin are required.')]
    case UnsupportedValidExistingWorkingHeadAndPublished = 'file_unsupported_valid_existing_working_head_and_published';

    #[Message('The published representation is not bounded UTF-8.')]
    case UnsupportedPublishedRepresentationNotBoundedUtf8 = 'file_unsupported_published_representation_not_bounded_utf8';

    #[Message('This MDX page still holds its source. Its host converts it to blocks once (ADR-0233 d9) before the Files action reads it.')]
    case UnsupportedMdxPageStillHoldsSourceHost = 'file_unsupported_mdx_page_still_holds_source_host';

    #[Message('MDX import requires bounded UTF-8 with LF line endings.')]
    case InvalidMdxImportRequiresBoundedUtf82 = 'file_invalid_mdx_import_requires_bounded_utf82';

    #[Message('TSX source must be bounded UTF-8 text.')]
    case InvalidTsxSourceMustBoundedUtf8 = 'file_invalid_tsx_source_must_bounded_utf8';

    #[Message('The TSX file could not be lowered to an admitted block document.')]
    case InvalidTsxFileCouldNotLoweredAdmitted = 'file_invalid_tsx_file_could_not_lowered_admitted';

    #[Message('The current published snapshot cannot be verified.')]
    case UnsupportedCurrentPublishedSnapshotCannotVerified = 'file_unsupported_current_published_snapshot_cannot_verified';

    #[Message('Explicit confirmation of this inspection is required.')]
    case ConfirmationExplicitConfirmationInspectionRequired = 'file_confirmation_explicit_confirmation_inspection_required';

    #[Message('The working head changed since inspection. Nothing changed. Reload comparison.')]
    case HeadMovedWorkingHeadChangedSinceInspectionNothing = 'file_head_moved_working_head_changed_since_inspection_nothing';

    #[Message('The published version changed since inspection. Nothing changed. Reload comparison.')]
    case PinMovedPublishedVersionChangedSinceInspectionNothing = 'file_pin_moved_published_version_changed_since_inspection_nothing';

    #[Message('The codec or file placement changed since inspection. Reload comparison.')]
    case PlacementMovedCodecOrFilePlacementChangedSince = 'file_placement_moved_codec_or_file_placement_changed_since';

    #[Message('The local file changed since inspection. Nothing changed. Reload comparison.')]
    case FileMovedLocalFileChangedSinceInspectionNothing = 'file_file_moved_local_file_changed_since_inspection_nothing';

    #[Message('The local file placement changed during inspection. Nothing changed.')]
    case PlacementMovedLocalFilePlacementChangedDuringInspection = 'file_placement_moved_local_file_placement_changed_during_inspection';

    // Hand-keyed (not generated)

    /** An MDX page file's first validation error, already keyed upstream, passed through with this code (English unchanged). */
    #[Message(':detail')]
    case InvalidDetail = 'file_invalid_detail';

    #[Message('The MDX page file is not valid.')]
    case MdxFileInvalid = 'file_mdx_invalid';

    #[Message('Local file reconciliation requires verified central residency with no active tenancy.')]
    case ReconciliationNeedsCentralResidency = 'file_reconciliation_needs_central_residency';
}
