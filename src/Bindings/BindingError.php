<?php

namespace Splicewire\Beam\Ux\Bindings;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a binding, Repeat or Record Page declaration was refused (localization 23; ticket 13's pattern). The English is
 * byte-identical to the literal the refusal carried before.
 *
 * The cases marked GENERIC replace a forwarded internal message (coord-lead ruling (b), 23:32Z). The raw text is kept
 * on `DiagnosedValidationException::$diagnostic` and never rendered.
 */
enum BindingError: string implements CodedError
{
    use IsCodedError;

    // Repeat

    #[Message('A Repeat requires a unique block id.')]
    case RepeatIdRequired = 'repeat_id_required';

    #[Message('Unknown saved query field.')]
    case RepeatQueryFieldUnknown = 'repeat_query_field_unknown';

    #[Message('The Repeat limit must be between 1 and 100.')]
    case RepeatLimitRange = 'repeat_limit_range';

    /** GENERIC: replaces a forwarded RepeatQueryData refusal (BindingResolver). */
    #[Message('The Repeat record selection is invalid.')]
    case RepeatSelectionInvalid = 'repeat_selection_invalid';

    #[Message('Record selections require a registered Collection source.')]
    case RecordSelectionNeedsCollection = 'repeat_selection_needs_collection';

    #[Message('This child-page source does not declare filters or sorts.')]
    case ChildSourceNoFilters = 'repeat_child_source_no_filters';

    #[Message('Nested Repeat requires an owner-declared Collection relation.')]
    case NestedRepeatNeedsRelation = 'repeat_nested_needs_relation';

    #[Message('Nested Repeat must name a declared to-many relation.')]
    case NestedRepeatToManyRequired = 'repeat_nested_to_many_required';

    #[Message('A relation Repeat accepts only a limit between 1 and 100.')]
    case RelationRepeatLimitRange = 'repeat_relation_limit_range';

    #[Message('A nested Repeat requires a unique block id.')]
    case NestedRepeatIdRequired = 'repeat_nested_id_required';

    #[Message('A nested Repeat requires an owner-declared to-many relation.')]
    case NestedRepeatOwnerToMany = 'repeat_nested_owner_to_many';

    #[Message('Unknown Repeat declaration field.')]
    case RepeatDeclarationFieldUnknown = 'repeat_declaration_field_unknown';

    #[Message('This Repeat query uses an undeclared field.')]
    case RepeatQueryFieldUndeclared = 'repeat_query_field_undeclared';

    /** GENERIC: replaces a forwarded RepeatQueryData refusal (BindingBodyGuard). */
    #[Message('This Repeat query is invalid.')]
    case RepeatQueryInvalid = 'repeat_query_invalid';

    #[Message('A Repeat must name an offered Collection or a declared relation in its outer Record.')]
    case RepeatSourceUnresolved = 'repeat_source_unresolved';

    #[Message('The Repeat source is not offered by this Manifest Profile.')]
    case RepeatSourceNotOffered = 'repeat_source_not_offered';

    #[Message('The Collection is not offered by this Manifest Profile.')]
    case CollectionNotOffered = 'binding_collection_not_offered';

    // Field bindings

    #[Message('A text binding takes plain fields only; rich fields require a declared rich-field component.')]
    case TextBindingPlainOnly = 'binding_text_plain_only';

    #[Message('A binding cannot target an executable attribute or a raw HTML sink.')]
    case UnsafeTarget = 'binding_unsafe_target';

    #[Message('A rich field requires its matching safe content component; MDX is never a rich field.')]
    case RichFieldComponentRequired = 'binding_rich_field_component_required';

    #[Message('The binding field does not fit the declared attribute [:attribute].')]
    case AttributeMismatch = 'binding_attribute_mismatch';

    /** GENERIC: replaces a forwarded FieldPath refusal (BindingFieldValidator). */
    #[Message('The binding field path is invalid.')]
    case FieldPathInvalid = 'binding_field_path_invalid';

    #[Message('A Field Binding needs a Record in scope.')]
    case FieldBindingNeedsRecord = 'binding_needs_record';

    #[Message('This Collection has no Record Page; a binding to url is unavailable (ADR-0229 d11).')]
    case UrlBindingNeedsRecordPage = 'binding_url_needs_record_page';

    #[Message('A binding field schema must have a resolvable local reference.')]
    case FieldSchemaRefUnresolvable = 'binding_schema_ref_unresolvable';

    #[Message('A binding field schema reference is missing.')]
    case FieldSchemaRefMissing = 'binding_schema_ref_missing';

    #[Message('The field [:path] is not declared by the offered Collection.')]
    case FieldUndeclaredByCollection = 'binding_field_undeclared';

    /** GENERIC: replaces a forwarded binding-walk refusal (BindingFieldEvolution). */
    #[Message("The body's bindings could not be checked against the Collection.")]
    case BodyBindingsUncheckable = 'binding_body_uncheckable';

    #[Message('Review the published pages affected by these dropped fields before freezing this version.')]
    case SchemaVersionImpactReview = 'binding_schema_version_impact_review';

    // Record Pages

    #[Message('A parameter segment needs a Record Page declaration.')]
    case ParameterSegmentNeedsRecordPage = 'record_page_parameter_segment';

    #[Message('The segment must be exactly {field} for the declared unique field.')]
    case SegmentMustBeField = 'record_page_segment_must_be_field';

    #[Message('Migrate beam-ux before saving a Record Page.')]
    case RecordPagesMigrationMissing = 'record_page_migration_missing';

    #[Message('A Record Page requires an offered schema Collection.')]
    case RecordPageNeedsCollection = 'record_page_needs_collection';

    #[Message('Record Pages require a registered database schema anchor.')]
    case RecordPagesNeedAnchor = 'record_page_needs_anchor';

    #[Message('The URL field must be a declared unique string field of the Collection.')]
    case UrlFieldNotUniqueString = 'record_page_url_field_not_unique_string';

    #[Message('The Record Page URL field must be included in the Collection Public Read projection.')]
    case UrlFieldNotPublic = 'record_page_url_field_not_public';

    #[Message('Title and SEO bindings must name declared string fields.')]
    case SeoBindingsNeedStringFields = 'record_page_seo_needs_string_fields';

    #[Message('A parent may have only one parameter child.')]
    case ParentOneParameterChild = 'record_page_parent_one_parameter_child';

    #[Message('A Collection may have only one Record Page per site.')]
    case CollectionOneRecordPage = 'record_page_collection_one_per_site';

    #[Message('Record Page declarations changed while acquiring locks. Retry against the current state.')]
    case RecordPagesChanged = 'record_page_declarations_changed';

    #[Message('The Record Page declaration must be a JSON object.')]
    case RecordPageNotObject = 'record_page_not_object';

    #[Message('Rebind the published Record Page before dropping its URL field or removing its unique-string declaration.')]
    case RebindBeforeDrop = 'record_page_rebind_before_drop';

    // Canvas bindings

    #[Message('Save a Record Page declaration before choosing a Record.')]
    case RecordPageRequiredForRecord = 'canvas_record_page_required';

    #[Message('Choose a valid Record value.')]
    case RecordValueInvalid = 'canvas_record_value_invalid';

    // Schema registration (localization 23A; range review B10 U3)

    #[Message('Rebind the published page [:title / :page] before this registration changes its Collection alias or makes it ambiguous [:aliases].')]
    case SchemaAliasRebindRequired = 'schema_alias_rebind_required';
}
