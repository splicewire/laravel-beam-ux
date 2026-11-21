<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Flows\Gates\AlwaysOn;
use Splicewire\Beam\Ux\Flows\BeamUxFlows;

/**
 * config-sections 11 (lead BJ-1310): beam-ux declares the Release panel's flow ONCE, as the owning package, with the realm
 * as a role (operator, tenant) and never part of the key.
 */
class BeamUxFlowsTest extends AuthoredComponentNativeTestCase
{
    public function test_the_package_declares_the_release_panel_once_with_both_realms_as_roles(): void
    {
        $catalog = app(FlowCatalog::class);

        $this->assertTrue($catalog->has('releases'));
        $this->assertTrue($catalog->has('releases.panel'));
        $this->assertSame('splicewire/laravel-beam-ux', $catalog->registrantOf('releases.panel'));

        $panel = BeamUxFlows::flow('releases.panel');
        $this->assertSame(['operator', 'tenant'], $panel->roles);
        $this->assertSame(AlwaysOn::class, $panel->gate);
        $this->assertSame([], $panel->routes, 'a host-rendered Frame page');
        $this->assertSame([], array_filter($catalog->keys(), fn (string $key): bool => str_contains($key, 'operator') || str_contains($key, 'tenant')),
            'the realm is never part of a key');
    }
}
