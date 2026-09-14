<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Access\EntryRequirement;
use Splicewire\Beam\Ux\Access\Right;
use Splicewire\Beam\Ux\Access\TokenAccessGate;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class EntryRequirementsTest extends TestCase
{
    public function test_an_absent_requirement_denies_even_when_the_host_gate_allows_everything(): void
    {
        $entry = new BeamUxEntry(['requirements' => ['example']]);
        $resolver = new EntryAccessResolver(new class implements EntryAccessGate
        {
            public function allows(?Authenticatable $actor, BeamUxEntry $entry, Right $right): bool
            {
                return true;
            }

            public function knows(string $token): bool
            {
                return true;
            }
        });
        $this->assertFalse($resolver->canRender(null, [$entry]));
        $this->assertFalse($resolver->canList(null, [$entry]));
    }

    public function test_requirement_is_live_and_conjunctive_with_existing_rights(): void
    {
        $requirement = new class implements EntryRequirement
        {
            public bool $enabled = true;

            public function allows(?Authenticatable $actor, BeamUxEntry $entry): bool
            {
                return $this->enabled;
            }
        };
        $this->app->instance('beam.ux.requirement.example', $requirement);
        $root = new BeamUxEntry(['requirements' => ['example']]);
        $child = new BeamUxEntry;
        $resolver = new EntryAccessResolver(new TokenAccessGate);
        $this->assertTrue($resolver->canRender(null, [$root, $child]));
        $child->access = [];
        $this->assertFalse($resolver->canRender(null, [$root, $child]), 'empty rights must still deny');
        $child->access = null;
        $requirement->enabled = false;
        $this->assertFalse($resolver->canRender(null, [$root, $child]));
        $this->assertFalse($resolver->canList(null, [$root, $child]));
    }

    public function test_requirement_bearing_artifacts_never_receive_public_immutable_caching(): void
    {
        $gate = new PublicEntryGate(new EntryPathResolver, new EntryAccessResolver(new TokenAccessGate));
        $this->assertTrue($gate->isRestricted([new BeamUxEntry(['requirements' => ['example']])]));
        $this->assertFalse($gate->isRestricted([new BeamUxEntry]));
    }

    public function test_missing_parent_and_cycles_cannot_drop_ancestor_requirements(): void
    {
        $paths = new EntryPathResolver;
        $orphan = new BeamUxEntry(['id' => 'child', 'parent_id' => 'missing']);
        $orphan->setRelation('parent', null);
        $this->assertNull($paths->ancestry($orphan));
        $parent = new BeamUxEntry(['id' => 'parent', 'parent_id' => 'child']);
        $orphan->parent_id = 'parent';
        $orphan->setRelation('parent', $parent);
        $parent->setRelation('parent', $orphan);
        $this->assertNull($paths->ancestry($orphan));
    }
}
