<?php

namespace Splicewire\Beam\Ux\Bindings;

/** A formerly declared saved query no longer has a field/alias; reads use the Repeat's empty state. */
final class DroppedBindingField extends \InvalidArgumentException {}
