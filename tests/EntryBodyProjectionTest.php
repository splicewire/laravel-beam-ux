<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;

/**
 * F06 — the entry body projection surfaces the ticket-14 `composable` editability tier to the frontend
 * host. The Puck canvas gate must key on this AUTHORITATIVE flag (not merely on body shape), so a
 * non-composable behavior-realm entry stays sealed even if it somehow carried a Puck body. Proving the
 * DTO carries the flag both ways is the package-side half of that contract (the host-side gate lives in
 * audiostud's MainframeHost).
 */
class EntryBodyProjectionTest extends TestCase
{
    public function test_projection_carries_composable_true(): void
    {
        $data = new BeamUxEntryBodyData(
            slug: 'home',
            type: 'page',
            schema: null,
            body: ['content' => [['type' => 'Hero']]],
            composable: true,
        );

        $array = $data->toArray();

        $this->assertArrayHasKey('composable', $array);
        $this->assertTrue($array['composable']);
    }

    public function test_projection_carries_composable_false(): void
    {
        $data = new BeamUxEntryBodyData(
            slug: 'login',
            type: 'page',
            schema: null,
            // A behavior-realm entry that (contrived) carries a Puck-shaped body — the gate must still
            // seal it off the `composable=false` flag, never off the body shape.
            body: ['content' => [['type' => 'AuthForm']]],
            composable: false,
        );

        $array = $data->toArray();

        $this->assertArrayHasKey('composable', $array);
        $this->assertFalse($array['composable']);
    }

    public function test_projection_defaults_composable_true(): void
    {
        $data = new BeamUxEntryBodyData(
            slug: 'about',
            type: 'page',
            schema: null,
            body: [],
        );

        $this->assertTrue($data->toArray()['composable']);
    }
}
