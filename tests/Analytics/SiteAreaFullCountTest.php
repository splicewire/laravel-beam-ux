<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Ux\Analytics\SiteArea;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;

/**
 * attention-surfaces 29: a Site area figure is the FULL count of its destination's rows, not one Frame page of them.
 * beam's handler pages a queried list in its Frame port (`index()`), and serves the whole list as `rows()`. A site
 * with more Redirects than Frame's per-page cap
 * ({@see ParticleFrameResourceHandler::FRAME_MAX_PER_PAGE}) is the case where the two answers differ: read through
 * `index()`, the figure would count one page envelope, not 105 Redirects.
 */
class SiteAreaFullCountTest extends SiteAreaTestCase
{
    private const REDIRECTS = 105;

    public function test_the_redirects_figure_counts_every_redirect_beyond_one_frame_page(): void
    {
        $this->assertGreaterThan(ParticleFrameResourceHandler::FRAME_MAX_PER_PAGE, self::REDIRECTS, 'the site must hold more rows than one Frame page');

        BeamUxRedirect::withoutEvents(function (): void {
            for ($i = 1; $i <= self::REDIRECTS; $i++) {
                BeamUxRedirect::create(['realm' => 'site', 'path' => "/old-{$i}", 'match' => 'exact', 'target_kind' => 'path', 'target_path' => '/about']);
            }
        });
        $this->actingAs($this->reader(7, ['os.operate', 'analytics.view'], editor: true));

        $figures = $this->figures();

        $this->assertSame(self::REDIRECTS, $figures['redirects']['value']);
        // The Redirects page itself answers one page of them, with the full total beside it.
        $page = $this->getJson('frame/resources/'.SiteArea::REDIRECTS.'?per_page=100')->assertOk();
        $this->assertCount(ParticleFrameResourceHandler::FRAME_MAX_PER_PAGE, $page->json('data'));
        $this->assertSame(self::REDIRECTS, $page->json('total'));
    }
}
