<?php

namespace Splicewire\Beam\Ux\Tests;

use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavRegistry;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\LocalizedNavFields;

/**
 * frame-foundation 82: a host-authored section's `input.static` rows and `input.groups` labels are the author's words, so
 * they reach the SPA in the request's language at ANY depth, through the one top-level path
 * ({@see FrameNavContribution::localizedNodes()}). Before 82 a host section's title came out marked while every row and
 * group tab under it went out raw: the flagship's 10 English labels under `en-XA`.
 */
class FrameNavLocalizedAtDepthTest extends TestCase
{
    private string $lang;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('frame.realms', ['operator' => []]);
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

    /**
     * The flagship's shape: one host section whose children are all static rows, two of them folded under a group tab, so
     * a row sits at depth 1 and two at depth 2.
     */
    private function hostSection(): void
    {
        $this->app->make(NavRegistry::class)->register('operator', fn () => [InvocableNavItem::make(
            title: 'Knowledge',
            invocable: FrameResourcesInvocable::NAME,
            input: [
                'section' => 'knowledge',
                'realm' => 'operator',
                'static' => [
                    ['title' => 'Overview', 'href' => '/operator/knowledge', 'routeName' => 'knowledge.overview', 'navOrder' => 1],
                    ['title' => 'Visualize', 'href' => '/operator/graph/visualize', 'routeName' => 'knowledge.graph.visualize', 'navOrder' => 2],
                    ['title' => 'Concepts', 'href' => '/operator/graph/concepts', 'navOrder' => 3],
                ],
                'groups' => [['label' => 'Graph', 'match' => 'graph']],
            ],
            href: '/operator/knowledge',
            routeName: 'knowledge.section',
        )], by: 'host');
    }

    private function nav(): array
    {
        return (new class(...self::deps($this->app)) extends FrameNavContribution
        {
            public function read(string $realm): array
            {
                return $this->navigation($realm)->toArray();
            }
        })->read('operator');
    }

    private static function deps($app): array
    {
        return array_map(fn (string $class) => $app->make($class), [
            \Splicewire\Beam\Ux\Frame\RouteContextProjector::class,
            \Splicewire\Beam\Ux\Frame\RouteContextValidator::class,
            NavRegistry::class,
            \Splicewire\Beam\Realm\RealmRegistry::class,
            \Illuminate\Http\Request::class,
        ]);
    }

    public function test_every_title_at_every_depth_and_the_authored_input_are_marked_under_the_pseudo_locale(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);
        $this->hostSection();

        $nav = $this->nav();
        $section = $nav['items'][0];

        $this->assertSame(PseudoLocale::transform('Knowledge'), $section['title']);
        $this->assertSame(
            [PseudoLocale::transform('Overview'), PseudoLocale::transform('Graph')],
            array_column($section['children'], 'title'),
        );
        $this->assertSame(
            [PseudoLocale::transform('Visualize'), PseudoLocale::transform('Concepts')],
            array_column($section['children'][1]['children'], 'title'),
            'a row folded under a group tab is depth 2; a row with no route name is presented as its literal',
        );
        $this->assertSame([], LocalizedNavFields::unmarked($nav), 'the manifest contract: no localized field went out raw');
    }

    public function test_a_row_and_a_group_tab_read_the_hosts_own_route_keys(): void
    {
        $this->lang = sys_get_temp_dir().'/ff82-nav-lang-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->lang.'/es', 0777, true);
        file_put_contents($this->lang.'/es/nav.php', "<?php return ['routes' => ["
            ."'knowledge/overview' => ['title' => 'Resumen'], 'knowledge/graph/visualize' => ['title' => 'Visualizar'],"
            ."'graph/section' => ['title' => 'Grafo']]];");
        $this->app->useLangPath($this->lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
        $this->app->setLocale('es');
        $this->hostSection();

        $section = $this->nav()['items'][0];

        $this->assertSame(['Resumen', 'Grafo'], array_column($section['children'], 'title'));
        $this->assertSame(['Visualizar', 'Concepts'], array_column($section['children'][1]['children'], 'title'));
        $this->assertSame(['Resumen', 'Visualizar', 'Concepts'], array_column($section['input']['static'], 'title'));
        $this->assertSame(['Grafo'], array_column($section['input']['groups'], 'label'));
    }

    public function test_the_check_names_an_unlocalized_title_at_any_depth(): void
    {
        config(['beam.localization.pseudo' => true]);
        $marked = PseudoLocale::transform('Section');

        $this->assertSame([], LocalizedNavFields::unmarked(['items' => [['title' => $marked, 'children' => []]]]));
        $this->assertSame(['items.0.children.0.children.0.title = Visualize'], LocalizedNavFields::unmarked(['items' => [
            ['title' => $marked, 'children' => [['title' => $marked, 'children' => [['title' => 'Visualize']]]]],
        ]]));
        $this->assertSame(
            ['items.0.input.static.0.title = Guest Links', 'items.0.input.groups.0.label = Graph'],
            LocalizedNavFields::unmarked(['items' => [['title' => $marked,
                'input' => ['static' => [['title' => 'Guest Links']], 'groups' => [['label' => 'Graph', 'match' => 'graph']]]]]]),
        );
    }
}
