<?php

namespace Splicewire\Beam\Ux\Tests;

use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavNode;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;

/**
 * localization 11 — the frame nav's declared text reads in the request's language (ruling 01 §3):
 *
 * - a section header is `<ns>::nav.sections.<key>.label`, `<ns>` the registrant of the seat that supplied the header;
 * - a static row's title is `<ns>::nav.routes.<a/b>.title` by its route name, in the same namespace; a row with no route
 *   name has no identity and keeps its literal;
 * - the Developer zone's header is beam-ux's own `beam-ux::nav.routes.developer/section.title`.
 */
class NavSectionLocaleTest extends TestCase
{
    private string $lang;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: 'calendars', realm: 'tenant', label: 'Calendars', icon: 'Calendar', href: '/calendars', order: 10,
            entitlement: null, permission: null, audience: NavAudience::Product,
            static: [
                ['title' => 'Usage', 'href' => '/usage', 'routeName' => 'usage.index'],
                ['title' => 'Help', 'href' => '/help'],
            ],
        ), by: 'splicewire/laravel-beam-ux');
        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: 'tools', realm: 'tenant', label: 'Tools', icon: 'Code', href: '/tools', order: 20,
            entitlement: null, permission: null, audience: NavAudience::Developer,
        ), by: 'splicewire/laravel-beam-ux');
    }

    /** @param  array<string, array<string, mixed>>  $files  path under the lang root => lines */
    private function lines(array $files): void
    {
        $this->lang = sys_get_temp_dir().'/beam-ux-nav-'.getmypid().'-'.bin2hex(random_bytes(4));
        foreach ($files as $path => $content) {
            @mkdir(dirname("{$this->lang}/{$path}"), 0777, true);
            file_put_contents("{$this->lang}/{$path}", '<?php return '.var_export($content, true).';');
        }
        $this->app->useLangPath($this->lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
    }

    /** @return array<int, NavNode> */
    private function project(): array
    {
        return $this->app->make(NavSectionProjector::class)->project('tenant');
    }

    private function seat(string $key): InvocableNavItem
    {
        foreach ($this->project() as $node) {
            if ($node instanceof InvocableNavItem && ($node->input['section'] ?? null) === $key) {
                return $node;
            }
        }
        $this->fail("No seat [{$key}].");
    }

    /** @return array<string, string> href => title, for a seat's static rows */
    private function rows(InvocableNavItem $seat): array
    {
        return array_column($seat->input['static'], 'title', 'href');
    }

    private function developerHeader(): NavNode
    {
        foreach ($this->project() as $node) {
            if ($node->routeName === 'developer.section') {
                return $node;
            }
        }
        $this->fail('No Developer header.');
    }

    public function test_the_english_nav_is_unchanged_with_no_line(): void
    {
        $this->lines([]);

        $seat = $this->seat('calendars');
        $this->assertSame('Calendars', $seat->title);
        $this->assertSame(['/usage' => 'Usage', '/help' => 'Help'], $this->rows($seat));
        $this->assertSame('Developer', $this->developerHeader()->title);
    }

    public function test_a_header_and_a_static_row_read_their_keys_in_the_seats_namespace(): void
    {
        $this->lines(['vendor/beam-ux/es/nav.php' => [
            'sections' => ['calendars' => ['label' => 'Calendarios']],
            'routes' => ['usage/index' => ['title' => 'Uso'], 'developer/section' => ['title' => 'Desarrollo']],
        ]]);
        $this->app->setLocale('es');

        $seat = $this->seat('calendars');
        $this->assertSame('Calendarios', $seat->title);
        // A row with no route name has no identity: its literal stands.
        $this->assertSame(['/usage' => 'Uso', '/help' => 'Help'], $this->rows($seat));
        $this->assertSame('Desarrollo', $this->developerHeader()->title);
    }

    public function test_no_declared_nav_text_is_plain_english_under_the_pseudo_locale(): void
    {
        $this->lines([]);
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);

        $seat = $this->seat('calendars');
        $this->assertTrue(PseudoLocale::isMarked($seat->title), $seat->title);
        foreach ($this->rows($seat) as $title) {
            $this->assertTrue(PseudoLocale::isMarked($title), $title);
        }
        $this->assertTrue(PseudoLocale::isMarked($this->developerHeader()->title));
    }
}
