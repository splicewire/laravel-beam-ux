<?php

namespace Splicewire\Beam\Ux\Components;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why an Authored Component save, rename, restore or publish was refused (localization 23, the laravel-beam-ux sweep;
 * ticket 13's pattern). Each case's value is the stable `code` a client receives beside the localized message. Its
 * English is byte-identical to the literal the refusal carried before.
 */
enum ComponentError: string implements CodedError
{
    use IsCodedError;

    #[Message('A component dependency has no readable Working Head.')]
    case DependencyUnreadable = 'component_dependency_unreadable';

    #[Message('A component version has no readable body snapshot.')]
    case VersionUnreadable = 'component_version_unreadable';

    #[Message('An Authored Component requires one root block and its declared interface.')]
    case RootRequired = 'component_root_required';

    #[Message('The component interface is invalid: :detail')]
    case InterfaceInvalid = 'component_interface_invalid';

    #[Message('The component name must be saved with its interface.')]
    case NameWithInterface = 'component_name_with_interface';

    #[Message('The component root must be a node in its realm profile.')]
    case RootNotNode = 'component_root_not_node';

    #[Message('A component prop and Slot cannot share a name.')]
    case PropSlotNameClash = 'component_prop_slot_name_clash';

    #[Message('A Slot accepts only its literal name.')]
    case SlotLiteralName = 'component_slot_literal_name';

    #[Message('A Slot cannot replace a component runtime channel.')]
    case SlotRuntimeChannel = 'component_slot_runtime_channel';

    #[Message('A component must declare each Slot only once.')]
    case SlotDeclaredTwice = 'component_slot_declared_twice';

    #[Message('A registered component already uses this name.')]
    case NameRegistered = 'component_name_registered';

    #[Message('A blocks layout or template requires exactly one unnamed Slot.')]
    case LayoutUnnamedSlot = 'component_layout_unnamed_slot';

    #[Message('A component in use cannot be renamed.')]
    case RenameInUse = 'component_rename_in_use';

    #[Message('Component names must be unique in their realm.')]
    case NameNotUnique = 'component_name_not_unique';

    #[Message('Published component names must be unique in their realm. Publish the rename first or in the same Release.')]
    case PublishedNameNotUnique = 'component_published_name_not_unique';

    #[Message('Components cannot contain a cycle.')]
    case Cycle = 'component_cycle';

    #[Message('A referenced component is unavailable in this realm.')]
    case ReferenceUnavailable = 'component_reference_unavailable';

    #[Message('Publish the component :name first, or include it in the same Release.')]
    case PublishDependencyFirst = 'component_publish_dependency_first';

    #[Message('Publish the renamed component first, or include its matching version in the same Release.')]
    case PublishRenameFirst = 'component_publish_rename_first';

    #[Message('The component reservation conflicted. Retry the save against the current head.')]
    case ReservationConflicted = 'component_reservation_conflicted';

    #[Message('Component names must be unique in their realm; this name is taken.')]
    case NameTaken = 'component_name_taken';

    #[Message('Cannot restore: published component name :name conflicts with another published component. Rename the conflicting component first.')]
    case RestoreNameConflict = 'component_restore_name_conflict';

    #[Message('A component interface belongs only to the sole Authored Component root.')]
    case InterfaceOnlyOnRoot = 'component_interface_only_on_root';

    #[Message('This component is used by entries: :entries')]
    case InUse = 'component_in_use';
}
