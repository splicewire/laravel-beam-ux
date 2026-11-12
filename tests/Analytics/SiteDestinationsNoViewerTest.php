<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Illuminate\Auth\Access\AuthorizationException;
use Splicewire\Beam\Ux\Analytics\SiteDestinations;

/**
 * analytics-area 19 (charter shape, 2026-11-12): a Site read with NO viewer cannot be answered, so SiteDestinations says so with
 * an AuthorizationException BEFORE the authorizer is asked (the doctor's dashboard-tier audit classes that as UNKNOWN). A
 * signed-in viewer the page refuses still reads null, and an entitled viewer still reads the rows.
 *
 * A UNIT of SiteDestinations alone: it boots without laravel-beam-analytics and never loads SiteArea (the keys are literal), so
 * it runs on a vendor whose analytics copy predates the Areas API. The area- and audit-level legs are proved on a host.
 */
class SiteDestinationsNoViewerTest extends SiteAreaTestCase
{
    private const REDIRECTS = 'beam-ux-redirect';

    protected function destinations(): array
    {
        return ['beam-ux-sitemap-health', 'beam-ux-site-health', self::REDIRECTS];
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_filter(
            parent::getPackageProviders($app),
            fn (string $provider): bool => ! str_starts_with($provider, 'Splicewire\\Beam\\Analytics\\'),
        ));
    }

    public function test_with_no_viewer_a_site_read_raises_authorization_instead_of_answering_null(): void
    {
        $this->seedSite();
        $this->assertNull(request()->user(), 'precondition: no viewer');

        $this->expectException(AuthorizationException::class);
        $this->app->make(SiteDestinations::class)->rows(self::REDIRECTS);
    }

    public function test_a_signed_in_viewer_the_page_refuses_still_reads_null(): void
    {
        $this->seedSite();
        // analytics.view, but neither the operator reach nor entry editing: frame refuses the redirects list to this viewer.
        $reader = $this->reader(21, ['analytics.view']);
        $this->actingAs($reader);
        request()->setUserResolver(fn () => $reader);

        $this->assertNull($this->app->make(SiteDestinations::class)->rows(self::REDIRECTS));
    }

    public function test_an_entitled_viewer_still_reads_the_rows(): void
    {
        $this->seedSite();
        $reader = $this->reader(22, ['os.operate', 'analytics.view'], editor: true);
        $this->actingAs($reader);
        request()->setUserResolver(fn () => $reader);

        $this->assertCount(1, $this->app->make(SiteDestinations::class)->rows(self::REDIRECTS) ?? []);
    }
}
