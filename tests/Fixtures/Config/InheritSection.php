<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 08 Spec HIGH #3): a section with a parent OBJECT key `group` an operator may set, whose
 * children mix an operator-owned `label` and a HOST-owned `fixed`. An operator set of `/group` OWNS `/group/label` and
 * INHERITS `/group/fixed` from the host — the override never pins the host's value. The mirror must record that inherit
 * so a round trip (publish -> commit -> import on another worktree) reproduces the stored entry exactly, instead of
 * importing a destructive parent set the host-ceiling check refuses.
 */
class InheritGroup extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string $fixed,
    ) {}
}

#[ConfigRoot(root: 'inherit-fixture', title: 'Inherit Fixture', description: 'A parent object with an inherited host child.')]
class InheritSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public InheritGroup $group,
    ) {}
}
