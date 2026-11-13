<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry;
use Splicewire\Beam\Analytics\Areas\AreaDefinition;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Analytics\SiteArea;
use Splicewire\Beam\Ux\Analytics\SiteAreaAbility;

/**
 * The Site area (analytics-area ticket 10), through the frame socket in the operator realm (Install scope): beam-ux
 * contributes it through the analytics area registry and nowhere else; each figure is the count its own page reports to the
 * same viewer; each link is the destination's own leaf in this realm.
 */
class SiteAreaTest extends SiteAreaTestCase
{
    public function test_beam_ux_contributes_the_site_area_through_the_analytics_registry_and_nowhere_else(): void
    {
        $areas = $this->app->make(AnalyticsAreaRegistry::class);

        $this->assertSame(SiteArea::class, $areas->resolve('site'));
        $this->assertSame('splicewire/laravel-beam-ux', $areas->registrantOf('site'));
        $this->assertNotContains(SiteArea::class, AnalyticsAreaRegistry::PACKAGE, 'the analytics package does not ship it');
        $this->assertFalse((new AnalyticsAreaRegistry($this->app))->has('site'), 'a registry that missed the contribution has no Site area');
        // analytics-area 20: the area's own ability is SiteAreaAbility (site.edit in tenant scope; DiagnosticsAbility here, Install).
        $this->assertSame(['entitlement:analytics.view', SiteAreaAbility::NAME], AreaDefinition::of(SiteArea::class)->requires(), 'the gate is analytics.view AND the Site area ability');
        $this->assertTrue($this->app->make(ParticleResourceRegistry::class)->has('analytics-site'));
    }

    public function test_each_figure_is_the_count_its_own_page_reports_to_the_same_viewer(): void
    {
        $this->seedSite();
        $this->actingAs($this->reader(7, ['os.operate', 'analytics.view'], editor: true));

        $figures = $this->figures();

        $this->assertSame(['published', 'indexed', 'sitemap-issues', 'redirects', 'mirror'], array_keys($figures));
        $this->assertSame([3, 2, 1, 1], [$figures['published']['value'], $figures['indexed']['value'], $figures['sitemap-issues']['value'], $figures['redirects']['value']]);

        $this->assertSame($this->listed(SiteArea::SITEMAP_HEALTH, ['type' => 'page', 'published' => 'true']), $figures['published']['value']);
        $this->assertSame($this->listed(SiteArea::SITEMAP_HEALTH, ['type' => 'page', 'indexed' => 'true']), $figures['indexed']['value']);
        $this->assertSame($this->listed(SiteArea::SITEMAP_HEALTH, ['type' => 'page', 'published' => 'true', 'indexed' => 'false']), $figures['sitemap-issues']['value']);
        $this->assertSame($this->listed(SiteArea::REDIRECTS), $figures['redirects']['value']);
        $this->assertSame('5 not-yet-saved', $figures['mirror']['value']);
        $this->assertSame($this->listed(SiteArea::SITE_HEALTH, ['state' => 'not-yet-saved']), 5);
    }

    public function test_the_card_is_the_same_figures_as_the_page(): void
    {
        $this->seedSite();
        $this->actingAs($this->reader(7, ['os.operate', 'analytics.view'], editor: true));

        $card = $this->getJson('frame/resources/analytics-site/summary')->assertOk();
        $figures = [];
        foreach ($card->json('figures') ?? $card->json('data.figures') ?? [] as $figure) {
            $figures[$figure['key']] = $figure['value'];
        }

        $this->assertSame(array_map(fn (array $figure) => $figure['value'], $this->figures()), $figures);
    }

    public function test_each_link_is_its_pages_own_leaf_in_this_realm(): void
    {
        $this->seedSite();
        $this->actingAs($this->reader(7, ['os.operate', 'analytics.view'], editor: true));

        $leaves = array_column($this->getJson('frame/manifest')->assertOk()->json('routeContext'), 'path', 'routeName');
        $figures = $this->figures();
        $base = '/'.$this->realm().'/';

        $this->assertSame($base.$leaves['beam-ux-sitemap-health.index'], $figures['published']['href']);
        $this->assertSame($base.$leaves['beam-ux-sitemap-health.index'], $figures['sitemap-issues']['href']);
        $this->assertSame($base.$leaves['beam-ux-redirect.index'], $figures['redirects']['href']);
        $this->assertSame($base.$leaves['beam-ux-site-health.index'], $figures['mirror']['href']);
    }

    public function test_a_page_this_reader_may_not_list_draws_no_figure_and_no_link(): void
    {
        $this->seedSite();
        // An operator holds the diagnostics ability through os.operate, but is no entry editor, so may not list Redirects.
        $this->actingAs($this->reader(9, ['os.operate', 'analytics.view']));

        $figures = $this->figures();

        $this->assertArrayNotHasKey('redirects', $figures);
        $this->assertArrayHasKey('published', $figures, 'control: the sitemap figures stay');
        $this->getJson('frame/resources/'.SiteArea::REDIRECTS)->assertForbidden();
    }
}
