<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Seed\SeedsEntries;

/**
 * ADR-0233 d8 with ADR-0213 and ADR-0215 (EP-27 slice 0): a package ships an Authored Component as a seeded entry, as it
 * ships pages. It is born published under its component name, so a page places it by its tag; it re-asserts from the
 * package while pristine; and a site's own edit to its copy is kept.
 */
class SeededComponentTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ADR-0215's provenance columns: a host without them keeps create-once, and nothing re-asserts.
        (require dirname(__DIR__).'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
    }

    public function test_a_package_seeds_an_authored_component_a_page_places_by_its_tag(): void
    {
        $component = $this->seedLegal('Each workspace runs in its own engine.');

        $this->assertSame(['component', 'authored', 'LegalEngine', 'package:acme/legal'],
            [$component->type->value, $component->getAttribute('component_kind')?->value ?? $component->getAttribute('component_kind'),
                $component->getAttribute('published_component_name'), $component->origin]);
        $this->assertNotNull($component->published_version, 'born published, as a seeded page is');

        $page = BeamUxEntry::create(['slug' => 'terms', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'terms']);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->block('LegalEngine')])->headVersion);
        $this->assertNotNull($page->fresh()->published_version, 'a page places the shipped component by its tag');
    }

    public function test_it_re_asserts_while_pristine_and_keeps_a_sites_edit(): void
    {
        $this->seedLegal('First text.');
        $component = $this->seedLegal('Second text.');
        $this->assertSame('Second text.', $this->text($component), 'pristine: the package\'s new text replaces it');

        $pub = app(EntryPublication::class);
        $pub->publishVersion($component->fresh(), $pub->recordDraft($component->fresh(), $this->body('Our own words.'))->headVersion);
        $component = $this->seedLegal('Third text.');
        $this->assertSame('Our own words.', $this->text($component), 'edited: the site\'s copy is kept');
    }

    private function seedLegal(string $text): BeamUxEntry
    {
        $seeder = new class
        {
            use SeedsEntries;

            public function run(array $body): ?BeamUxEntry
            {
                return $this->seedComponent('legal-engine', $body, ['title' => 'Platform'], origin: 'package:acme/legal');
            }
        };

        return $seeder->run($this->body($text))->fresh();
    }

    private function body(string $text): array
    {
        return [$this->block('p', [['kind' => 'text', 'value' => $text]]) + ['component' => ['name' => 'LegalEngine', 'props' => []]]];
    }

    private function text(BeamUxEntry $component): string
    {
        return (string) (app(AuthoredComponents::class)->working($component)[0]['children'][0]['value'] ?? '');
    }

    private function block(string $name, array $children = []): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'dynamic' => false, 'props' => [], 'children' => $children];
    }
}
