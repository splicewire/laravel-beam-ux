<?php

namespace Splicewire\Beam\Ux\Manifest;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why {@see ProfileLegality} refused a canvas node (localization 23A; range review B10 U1). Each case is a COMPLETE
 * refusal template: the node's id and path, and the authored names, are parameters, never key material. A refusal
 * that can name a node by id has two cases, the identified one (`node #:id at …`) and the anonymous one
 * (`node at …`), so no fragment of English is passed as a parameter. The English is byte-identical to the literal
 * the refusal carried before.
 */
enum ProfileError: string implements CodedError
{
    use IsCodedError;

    // The code band (ux-0227 d1).

    #[Message('node at [:path] carries an opaque node (verbatim code); this profile does not admit code (ux-0227 d1)')]
    case OpaqueNode = 'profile_opaque_node';

    #[Message('node at [:path] uses an undeclared component prop reference')]
    case UndeclaredPropReference = 'profile_undeclared_prop_reference';

    #[Message('node #:id at [:path] uses an undeclared component prop reference')]
    case UndeclaredPropReferenceIdentified = 'profile_undeclared_prop_reference_identified';

    #[Message('node at [:path] Slot accepts only a literal name')]
    case SlotLiteralName = 'profile_slot_literal_name';

    #[Message('node #:id at [:path] Slot accepts only a literal name')]
    case SlotLiteralNameIdentified = 'profile_slot_literal_name_identified';

    #[Message('node at [:path] uses an undeclared component slot')]
    case UndeclaredComponentSlot = 'profile_undeclared_component_slot';

    #[Message('node #:id at [:path] uses an undeclared component slot')]
    case UndeclaredComponentSlotIdentified = 'profile_undeclared_component_slot_identified';

    #[Message("node at [:path] carries the expression prop ':prop' (code); this profile does not admit code (ux-0227 d1)")]
    case ExpressionProp = 'profile_expression_prop';

    #[Message("node #:id at [:path] carries the expression prop ':prop' (code); this profile does not admit code (ux-0227 d1)")]
    case ExpressionPropIdentified = 'profile_expression_prop_identified';

    // The legality band.

    #[Message('node at [:path] text requires a text component prop')]
    case TextRequiresTextProp = 'profile_text_requires_text_prop';

    #[Message('node at [:path] has text where the parent admits none')]
    case TextNotAdmitted = 'profile_text_not_admitted';

    #[Message('node at [:path] uses undeclared named slot :slot')]
    case UndeclaredNamedSlot = 'profile_undeclared_named_slot';

    #[Message('node #:id at [:path] uses undeclared named slot :slot')]
    case UndeclaredNamedSlotIdentified = 'profile_undeclared_named_slot_identified';

    #[Message("node at [:path] uses the unknown block ':name', which is not in this profile")]
    case UnknownBlock = 'profile_unknown_block';

    #[Message("node #:id at [:path] uses the unknown block ':name', which is not in this profile")]
    case UnknownBlockIdentified = 'profile_unknown_block_identified';

    #[Message("node at [:path] block ':name' (category ':category') is not admitted here (the parent admits: :admitted)")]
    case BlockNotAdmitted = 'profile_block_not_admitted';

    #[Message("node #:id at [:path] block ':name' (category ':category') is not admitted here (the parent admits: :admitted)")]
    case BlockNotAdmittedIdentified = 'profile_block_not_admitted_identified';

    #[Message("node at [:path] block ':name' (category ':category') is not admitted here (the parent admits: nothing)")]
    case BlockNotAdmittedAnywhere = 'profile_block_not_admitted_anywhere';

    #[Message("node #:id at [:path] block ':name' (category ':category') is not admitted here (the parent admits: nothing)")]
    case BlockNotAdmittedAnywhereIdentified = 'profile_block_not_admitted_anywhere_identified';

    #[Message('node at [:path] component prop does not fit attribute :attribute')]
    case PropDoesNotFitAttribute = 'profile_prop_does_not_fit_attribute';

    #[Message('node #:id at [:path] component prop does not fit attribute :attribute')]
    case PropDoesNotFitAttributeIdentified = 'profile_prop_does_not_fit_attribute_identified';

    #[Message('node at [:path] has props that do not match the block schema: :errors')]
    case PropsDoNotMatchSchema = 'profile_props_do_not_match_schema';

    #[Message('node #:id at [:path] has props that do not match the block schema: :errors')]
    case PropsDoNotMatchSchemaIdentified = 'profile_props_do_not_match_schema_identified';
}
