<?php

namespace Splicewire\Beam\Ux\Containment;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a page's settings, Page Fields, File Language or URL change was refused (localization 23; ticket 13's pattern).
 * The English is byte-identical to the literal the refusal carried before; the case marked GENERIC replaces a forwarded
 * internal message (coord-lead ruling (b)).
 */
enum PageError: string implements CodedError
{
    use IsCodedError;

    #[Message('Edit page settings in the editor and publish their version.')]
    case SettingsEditorOnly = 'page_settings_editor_only';

    #[Message('Only pages have page settings.')]
    case SettingsOnlyPages = 'page_settings_only_pages';

    #[Message("A Hosted site's pages have no File Language (ADR-0233 d6).")]
    case HostedNoFileLanguage = 'page_hosted_no_file_language';

    #[Message('This host does not write pages in :language.')]
    case FileLanguageUnsupported = 'page_file_language_unsupported';

    #[Message('Only a page chooses its File Language here.')]
    case FileLanguageOnlyPages = 'page_file_language_only_pages';

    #[Message('This page cannot be written in :language on this host (ADR-0233 d5).')]
    case FileLanguageUnwritableAdr = 'page_file_language_unwritable_adr';

    #[Message('This page cannot be written in :language on this host.')]
    case FileLanguageUnwritable = 'page_file_language_unwritable';

    #[Message('No host class or tenant registered schema declares these Page Fields.')]
    case ChildFieldsUndeclared = 'page_child_fields_undeclared';

    #[Message('Migrate beam-ux (add_child_fields_to_beam_ux_entries_table) before a page declares Page Fields.')]
    case ChildFieldsMigrationMissing = 'page_child_fields_migration_missing';

    #[Message('This key is declared, so it cannot be kept as unclaimed. Set it as the page setting or Page Field it names.')]
    case UnclaimedKeyDeclaredSetting = 'page_unclaimed_key_declared_setting';

    #[Message('An unclaimed frontmatter key is a snake_case name.')]
    case UnclaimedKeyNotSnake = 'page_unclaimed_key_not_snake';

    #[Message('The unclaimed key [:key] is declared, so it cannot be kept as unclaimed.')]
    case UnclaimedKeyDeclared = 'page_unclaimed_key_declared';

    #[Message("This page's parent declares no Page Fields (ADR-0233 d4).")]
    case ParentDeclaresNoFields = 'page_parent_declares_no_fields';

    #[Message('The parent names no available Page Fields schema.')]
    case ParentFieldsSchemaUnavailable = 'page_parent_fields_schema_unavailable';

    /** GENERIC: replaces a forwarded Page Fields evaluation refusal (PageFields). */
    #[Message("These Page Fields cannot be evaluated against the parent's schema.")]
    case FieldsUnevaluable = 'page_fields_unevaluable';

    #[Message("The parent's Page Fields do not declare this field.")]
    case FieldUndeclared = 'page_field_undeclared';

    #[Message("These Page Fields do not conform to the parent's registered schema.")]
    case FieldsNonconforming = 'page_fields_nonconforming';

    #[Message('An MDX page prints its settings as frontmatter, which carries one line per value without surrounding spaces or quotes (ADR-0233 d3).')]
    case FrontmatterValueUnprintable = 'page_frontmatter_value_unprintable';

    // URL changes (ADR-0232 d2)

    #[Message('A changed entry does not exist.')]
    case ChangedEntryMissing = 'page_changed_entry_missing';

    #[Message('The proposed page parents contain a cycle.')]
    case ParentCycle = 'page_parent_cycle';

    #[Message('A page cannot publish under a missing or never-published parent.')]
    case ParentUnpublished = 'page_parent_unpublished';

    #[Message('The proposed pages would share a URL.')]
    case UrlShared = 'page_url_shared';
}
