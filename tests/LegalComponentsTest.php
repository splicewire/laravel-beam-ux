<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Mdx\BeamMdxServiceProvider;
use Splicewire\Beam\Mdx\Legal\LegalComponentsSeeder;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * ADR-0233 d8 (EP-27 slice 1; ruling 2): the four legal texts ship once, from laravel-beam-mdx, as package-origin
 * Authored Components a page places by tag: `<LegalAiProcessing />`, `<LegalDataRights />`, `<LegalEngine />` and
 * `<LegalPayments />`. They are seeded with the rest of a host's packages, and a site can still edit its copy.
 */
class LegalComponentsTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
    }

    public function test_the_four_legal_texts_ship_as_package_components_a_page_places_by_tag(): void
    {
        (new LegalComponentsSeeder)->run();

        $components = BeamUxEntry::query()->where('type', 'component')->orderBy('slug')->get();
        $this->assertSame(['LegalAiProcessing', 'LegalDataRights', 'LegalEngine', 'LegalPayments'],
            $components->map(fn ($c) => $c->getAttribute('published_component_name'))->all());
        $this->assertSame(['package:splicewire/laravel-beam-mdx'], $components->pluck('origin')->unique()->values()->all());
        $this->assertStringStartsWith('This site runs on the Splicewire platform.',
            app(AuthoredComponents::class)->working($components->firstWhere('slug', 'legal-engine'))[0]['children'][0]['value']);

        $page = BeamUxEntry::create(['slug' => 'privacy', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'privacy']);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->block('LegalDataRights'), $this->block('LegalPayments')])->headVersion);
        $this->assertNotNull($page->fresh()->published_version);

        (new LegalComponentsSeeder)->run();
        $this->assertSame(4, BeamUxEntry::query()->where('type', 'component')->count(), 'seeding again adds nothing');
    }

    public function test_the_package_registers_its_seed_where_beam_ux_is_installed(): void
    {
        $this->app->register(BeamMdxServiceProvider::class);

        $this->assertContains(LegalComponentsSeeder::class, array_map(fn ($step) => $step->seeder, app(BeamSeedManifest::class)->steps()));
    }

    private function block(string $name): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []];
    }
}
