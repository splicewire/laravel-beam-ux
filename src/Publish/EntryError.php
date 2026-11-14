<?php

namespace Splicewire\Beam\Ux\Publish;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why an entry's save, preview, publication, restore or input was refused (localization 23; ticket 13's pattern). The
 * English is byte-identical to the literal the refusal carried before; the case marked GENERIC replaces a forwarded
 * internal message (coord-lead ruling (b)).
 */
enum EntryError: string implements CodedError
{
    use IsCodedError;

    #[Message('The editor base version has moved. Reload or explicitly save your changes against the new base.')]
    case EditorBaseMoved = 'entry_editor_base_moved';

    #[Message('This entry has history but no valid Publication Pin. Repair its pin before editing.')]
    case PublicationPinInvalid = 'entry_publication_pin_invalid';

    #[Message('The named version does not belong to this entry or has no valid body snapshot.')]
    case VersionNotOfEntry = 'entry_version_not_of_entry';

    #[Message('The entry lifecycle refused the publish transition.')]
    case PublishRefused = 'entry_publish_refused';

    #[Message('No version [:ref] belongs to this entry; there is nothing to restore.')]
    case VersionNotFound = 'entry_version_not_found';

    #[Message('This entry references a missing particle. Repair its binding before saving.')]
    case ParticleMissing = 'entry_particle_missing';

    #[Message("This entry is authored as :format source, which cannot carry a canvas document. Saving one here would replace the source with an empty file. Edit it as :format source, or change the entry's format first.")]
    case SourceFormatNoCanvas = 'entry_source_format_no_canvas';

    /** GENERIC: replaces a forwarded style or CSS normalization refusal (EntryPublication). */
    #[Message('The body could not be prepared for saving.')]
    case BodyUnpreparable = 'entry_body_unpreparable';

    #[Message('The named version has no valid body snapshot.')]
    case VersionSnapshotInvalid = 'entry_version_snapshot_invalid';

    #[Message('The publication pin has no valid body snapshot.')]
    case PinSnapshotInvalid = 'entry_pin_snapshot_invalid';

    #[Message('save-blocks requires a blocks-kind entry.')]
    case BlocksKindRequired = 'entry_blocks_kind_required';

    #[Message("This entry declares a :kind body, not a form. Edit it through the :kind input, or change the entry's kind first.")]
    case FormBodyMismatch = 'entry_form_body_mismatch';

    #[Message("This form does not match the entry's schema: :errors")]
    case FormSchemaMismatch = 'entry_form_schema_mismatch';

    #[Message('This page already has an entry.')]
    case PageEntryExists = 'entry_page_entry_exists';

    #[Message('Choose a saved version of this entry.')]
    case PreviewVersionRequired = 'entry_preview_version_required';

    #[Message('Choose a saved version with a valid body snapshot.')]
    case PreviewVersionInvalid = 'entry_preview_version_invalid';

    #[Message('A live preview body may be at most 256 KiB.')]
    case PreviewBodyTooLarge = 'entry_preview_body_too_large';

    #[Message('A live preview may resolve at most 20 root Repeats.')]
    case PreviewRepeatsTooMany = 'entry_preview_repeats_too_many';

    #[Message('Only a component declares an authored or source kind.')]
    case ComponentKindOnlyComponent = 'entry_component_kind_only_component';

    #[Message('A component with a body cannot change its declared kind.')]
    case ComponentKindLocked = 'entry_component_kind_locked';

    #[Message('You are not entitled to author entries in this realm.')]
    case RealmNotEntitled = 'entry_realm_not_entitled';

    #[Message('Choose a JPEG, PNG, GIF, WebP or AVIF image up to 8 MiB.')]
    case ImageInvalid = 'entry_image_invalid';

    #[Message('A tenant Preview requires a tenant-bound actor.')]
    case TenantPreviewActorRequired = 'entry_tenant_preview_actor_required';

    #[Message('A tenant Preview requires an initialized tenant.')]
    case TenantPreviewUninitialized = 'entry_tenant_preview_uninitialized';

    #[Message('Tenant Site Host is not configured.')]
    case TenantHostUnconfigured = 'entry_tenant_host_unconfigured';

    #[Message('Tenant Site Host Preview route is not mounted.')]
    case TenantPreviewUnmounted = 'entry_tenant_preview_unmounted';
}
