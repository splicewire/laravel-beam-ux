<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Splicewire\Beam\Ux\Analytics\SiteArea;

/**
 * A page the Site area links to that this realm's console does not mount (PRD story 22: no link is ever dead). Here the
 * operator realm mounts Sitemap health and Site health but not Redirects. Redirects' list still answers this reader at its
 * frame URL in this harness, so its figure stands and equals that list; but no leaf of it is bound in the realm's route
 * context, so the figure carries no link, while the mounted pages keep theirs.
 */
class SiteAreaUnmountedTest extends SiteAreaTestCase
{
    protected function destinations(): array
    {
        return [SiteArea::SITEMAP_HEALTH, SiteArea::SITE_HEALTH];
    }

    public function test_a_page_with_no_leaf_in_this_realm_gets_no_link_and_its_figure_still_equals_its_list(): void
    {
        $this->seedSite();
        $this->actingAs($this->reader(7, ['os.operate', 'analytics.view'], editor: true));

        $leaves = array_column($this->getJson('frame/manifest')->assertOk()->json('routeContext'), 'path', 'routeName');
        $figures = $this->figures();

        $this->assertArrayNotHasKey('beam-ux-redirect.index', $leaves, 'the realm binds no Redirects leaf');
        $this->assertNull($figures['redirects']['href'], 'so the Redirects figure links nowhere');
        $this->assertSame($this->listed(SiteArea::REDIRECTS), $figures['redirects']['value'], 'and still equals its own list');
        $this->assertSame('/operator/'.$leaves['beam-ux-sitemap-health.index'], $figures['published']['href'], 'control: a mounted page keeps its link');
        $this->assertSame('/operator/'.$leaves['beam-ux-site-health.index'], $figures['mirror']['href'], 'control: a mounted page keeps its link');
    }
}
