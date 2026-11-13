<?php

namespace Splicewire\Beam\Ux\Tests;

use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavInvocableRegistry;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavRegistry;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;

/**
 * REVIEW-B7 (Codex, review-r1): beam-ux's U1 and U2. Both follow ruling 01 §3: a nav page's title belongs to the code
 * that AUTHORED it, while a section header belongs to the header seat.
 *
 * - U1: a host's code-authored NavRegistry tree resolves its page titles at emit, under the host's
 *   `nav.routes.<a/b>.title`. An invocable's expanded children (already localized) are left alone.
 * - U2: a static row in a merged seat is keyed by the seat that CONTRIBUTED it, not by the header seat.
 */
class ReviewB7AmendNavTest extends TestCase
{
    private string $lang;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function tearDown(): void
    {
        if (isset($this->lang) && is_dir($this->lang)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->lang, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->lang);
        }
        parent::tearDown();
    }

    /** es lines: beam-ux's `usage/index` title and the host's `host/reports` title. */
    private function spanish(): void
    {
        $this->lang = sys_get_temp_dir().'/b7-nav-lang-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->lang.'/vendor/beam-ux/es', 0777, true);
        file_put_contents($this->lang.'/vendor/beam-ux/es/nav.php', "<?php return ['routes' => ['usage/index' => ['title' => 'Uso']]];");
        mkdir($this->lang.'/es', 0777, true);
        file_put_contents($this->lang.'/es/nav.php', "<?php return ['routes' => ['host/reports' => ['title' => 'Informes']]];");
        $this->app->useLangPath($this->lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
        $this->app->setLocale('es');
    }

    private function seat(array $static = []): NavSection
    {
        return new NavSection(key: 'review-billing', realm: 'tenant', label: 'Billing', icon: 'Bank', href: '/billing', order: 10,
            entitlement: null, permission: null, audience: NavAudience::Product, static: $static);
    }

    /** The merged review-billing seat's projected node. */
    private function billingNode(): InvocableNavItem
    {
        foreach ($this->app->make(NavSectionProjector::class)->project('tenant') as $node) {
            if ($node instanceof InvocableNavItem && ($node->input['section'] ?? null) === 'review-billing') {
                return $node;
            }
        }
        $this->fail('missing the review-billing seat');
    }

    /** @return array<string, mixed> the merged review-billing seat's projected input */
    private function billing(): array
    {
        return $this->billingNode()->input;
    }

    private function hostNav(): ReviewB7ExposedNav
    {
        return $this->app->make(ReviewB7ExposedNav::class);
    }

    public function test_u2_a_merged_static_row_reads_its_contributors_namespace(): void
    {
        $sections = $this->app->make(NavSectionRegistry::class);
        $sections->register($this->seat(), by: 'splicewire/laravel-beam');
        $sections->register($this->seat([['title' => 'Usage', 'href' => '/usage', 'routeName' => 'usage.index']]), by: 'splicewire/laravel-beam-ux');
        $this->spanish();

        $this->assertSame('Uso', $this->billing()['static'][0]['title']);
    }

    public function test_u2_the_header_stays_with_the_header_seat_and_a_host_row_reads_the_host(): void
    {
        $sections = $this->app->make(NavSectionRegistry::class);
        $sections->register($this->seat([['title' => 'Usage', 'href' => '/usage', 'routeName' => 'usage.index']]), by: 'splicewire/laravel-beam-ux');
        $sections->register($this->seat([['title' => 'Reports', 'href' => '/reports', 'routeName' => 'host.reports']]), by: null);
        $this->spanish();

        // The header seat is beam-ux's; beam-ux's es has no `nav.sections.review-billing.label`, so the header keeps
        // its literal, while each row reads its own contributor.
        $node = $this->billingNode();
        $this->assertSame(['Uso', 'Informes'], array_column($node->input['static'], 'title'));
        $this->assertSame('Billing', $node->title);
        file_put_contents($this->lang.'/vendor/beam-ux/es/nav.php', "<?php return ['routes' => ['usage/index' => ['title' => 'Uso']], 'sections' => ['review-billing' => ['label' => 'Facturación']]];");
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
        $this->app->setLocale('es');
        $this->assertSame('Facturación', $this->billingNode()->title);
    }

    public function test_u1_a_host_authored_page_reads_its_host_route_key(): void
    {
        $this->app->make(NavRegistry::class)->register('tenant', fn () => [NavLink::make(title: 'Reports', href: '/reports', routeName: 'host.reports')], by: 'host');
        $this->spanish();

        $this->assertSame('Informes', $this->hostNav()->readNav('tenant')->items[0]->title);
    }

    public function test_u1_a_host_page_with_no_line_or_no_route_keeps_its_literal_and_english_is_unchanged(): void
    {
        $this->app->make(NavRegistry::class)->register('tenant', fn () => [
            NavLink::make(title: 'Settings', href: '/settings', routeName: 'host.settings'),
            NavLink::make(title: 'Help', href: '/help'),
        ], by: 'host');

        $this->assertSame(['Settings', 'Help'], array_map(fn ($n) => $n->title, $this->hostNav()->readNav('tenant')->items));
    }

    public function test_u1_a_host_page_is_marked_once_under_the_pseudo_locale(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->make(NavRegistry::class)->register('tenant', fn () => [NavLink::make(title: 'Reports', href: '/reports', routeName: 'host.reports')], by: 'host');
        $this->app->setLocale(Locales::PSEUDO);

        $title = $this->hostNav()->readNav('tenant')->items[0]->title;
        $this->assertTrue(PseudoLocale::isMarked($title), $title);
        $this->assertSame(PseudoLocale::transform('Reports'), $title);
    }

    public function test_u1_an_invocables_expanded_children_are_never_rekeyed_or_marked_twice(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);
        $already = PseudoLocale::transform('Invoices');
        $this->app->make(NavInvocableRegistry::class)->register(new class($already) implements Invocable
        {
            public function __construct(private string $title) {}

            public function name(): string
            {
                return 'review-b7.leaves';
            }

            public function binding(): Binding
            {
                return Binding::Local;
            }

            public function invoke(array $input): array
            {
                return ['items' => [NavLink::make(title: $this->title, href: '/invoices', routeName: 'host.reports')]];
            }
        });
        $this->app->make(NavRegistry::class)->register('tenant', fn () => [InvocableNavItem::make(title: 'Billing', invocable: 'review-b7.leaves', href: '/billing')], by: 'host');

        $item = $this->hostNav()->readNav('tenant')->items[0];
        $this->assertSame(PseudoLocale::transform('Billing'), $item->title);
        $this->assertSame([$already], array_map(fn ($n) => $n->title, $item->children));
    }
}

class ReviewB7ExposedNav extends FrameNavContribution
{
    public function readNav(string $realm)
    {
        return $this->navigation($realm);
    }
}
