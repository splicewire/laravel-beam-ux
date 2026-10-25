<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Containment\Reach;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseChanges;

/**
 * Reach (ADR-0232 d10; ADR-0231 d16's "used on 12 pages"): the live pages an entry changes. A page reaches itself, a
 * layout or template every page whose containment chain resolves to it, an Authored Component every page that uses it
 * directly or through another component, layout or template, and a theme every page. A Release lists its own pages
 * first, then what each component, layout or template member reaches, read in the tree its Preview shows.
 */
class ReachTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'add_preview_links_to_beam_ux_releases_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
    }

    public function test_a_page_reaches_itself_and_a_layout_reaches_every_live_page_whose_chain_resolves_to_it(): void
    {
        $layout = $this->entry('DocsLayout', 'layout');
        $docs = $this->livePage('docs', ['layout' => 'DocsLayout']);
        $api = $this->livePage('api', ['parent_id' => $docs->id]);
        $this->livePage('wide', ['parent_id' => $docs->id, 'layout' => 'WideLayout']);
        $this->livePage('elsewhere');
        // Never published: no visitor reaches it.
        $this->entry('draft-only', 'page')->forceFill(['segment' => 'draft-only', 'parent_id' => $docs->id])->save();

        $page = app(Reach::class)->of($api);
        $this->assertSame([[$api->id, '/docs/api']], array_map(fn ($p) => [$p->entryId, $p->path], $page->pages));

        $reach = app(Reach::class)->of($layout);
        $this->assertFalse($reach->everyPage);
        $this->assertSame(2, $reach->count);
        $this->assertSame([[$docs->id, '/docs'], [$api->id, '/docs/api']], array_map(fn ($p) => [$p->entryId, $p->path], $reach->pages),
            'the page declaring it and the child inheriting it; not the child that declares another');
    }

    public function test_a_component_reaches_the_pages_using_it_directly_or_through_a_component_or_a_layout(): void
    {
        $card = $this->liveComponent('card', $this->body('Card'));
        $this->liveComponent('hero', $this->body('Hero', ['Card']));
        $unused = $this->liveComponent('unused', $this->body('Unused'));
        $layout = $this->entry('CardLayout', 'layout');
        $this->publish($layout, [array_replace($this->node('div'), ['children' => [$this->node('Card'), $this->node('Slot')]])]);
        $direct = $this->livePage('direct', [], [$this->node('Card')]);
        $nested = $this->livePage('nested', [], [$this->node('Hero')]);
        $framed = $this->livePage('framed', ['layout' => 'CardLayout']);
        $this->livePage('plain');

        $reach = app(Reach::class)->of($card->fresh());

        $this->assertSame(3, $reach->count);
        $this->assertEqualsCanonicalizing([$direct->id, $nested->id, $framed->id], array_map(fn ($p) => $p->entryId, $reach->pages));
        $this->assertSame(0, app(Reach::class)->of($unused->fresh())->count, 'a component no page uses has no page to open');
    }

    public function test_a_theme_reaches_every_page(): void
    {
        $this->livePage('one');
        $this->livePage('two');

        $reach = app(Reach::class)->of($this->entry('site-theme', 'theme'));

        $this->assertTrue($reach->everyPage);
        $this->assertSame(2, $reach->count);
        $this->assertSame([], $reach->pages, '"every page" is listed as that, not page by page');
    }

    public function test_a_release_lists_its_own_pages_first_then_what_each_component_or_layout_member_reaches(): void
    {
        $pub = app(EntryPublication::class);
        $card = $this->liveComponent('card', $this->body('Card'));
        $layout = $this->entry('SiteLayout', 'layout');
        $this->publish($layout, [array_replace($this->node('div'), ['children' => [$this->node('Slot')]])]);
        $home = $this->livePage('home', ['layout' => 'SiteLayout']);
        $about = $this->livePage('about', [], [$this->node('Card')]);
        $moved = $this->livePage('old-path');
        $release = $this->releaseHolding([
            $moved->id => $pub->recordDraft($moved, [$this->node('div')], pageSettings: new EntryPageSettingsData(title: 'Moved', segment: 'new-path'))->headVersion,
            $card->id => $pub->recordDraft($card->fresh(), $this->body('Card', ['span']))->headVersion,
            $layout->id => $pub->recordDraft($layout->fresh(), [array_replace($this->node('section'), ['children' => [$this->node('Slot')]])])->headVersion,
        ]);

        $changes = app(ReleaseChanges::class)->of($release);

        $this->assertSame([[$moved->id, '/new-path', false]], array_map(fn ($p) => [$p->entryId, $p->path, $p->recordPage], $changes->pages),
            'its own page, at the URL the Release gives it');
        $reach = collect($changes->reach)->keyBy('entryId');
        $this->assertSame(['component', 'Card', 1, [$about->id]],
            [$reach[$card->id]->kind, $reach[$card->id]->name, $reach[$card->id]->count, array_map(fn ($p) => $p->entryId, $reach[$card->id]->pages)]);
        $this->assertSame(['layout', 'SiteLayout', 1, [$home->id]],
            [$reach[$layout->id]->kind, $reach[$layout->id]->name, $reach[$layout->id]->count, array_map(fn ($p) => $p->entryId, $reach[$layout->id]->pages)]);
    }

    /** @param array<string, string> $versions */
    private function releaseHolding(array $versions): BeamUxRelease
    {
        $release = tap((new BeamUxRelease)->forceFill([
            'title' => 'Changes', 'realm' => 'site', 'created_by' => 'user:author', 'edited_by' => 'user:author',
            'status' => 'draft', 'origin' => 'built',
        ]))->save();
        foreach ($versions as $entryId => $version) {
            $entry = BeamUxEntry::findOrFail($entryId);
            $entry->getConnection()->table('beam_ux_release_members')->insert([
                'id' => (string) Str::uuid(), 'release_id' => $release->id, 'entry_id' => $entryId, 'version_id' => $version,
                'observed_pin' => $entry->published_version, 'observed_marking' => $entry->workflow_marking,
            ]);
        }

        return $release;
    }

    private function livePage(string $segment, array $attributes = [], array $body = []): BeamUxEntry
    {
        $page = $this->entry($segment, 'page');
        $page->forceFill(['segment' => $segment, ...$attributes])->save();

        return $this->publish($page, $body === [] ? [$this->node('div')] : $body);
    }

    private function liveComponent(string $slug, array $body): BeamUxEntry
    {
        $component = BeamUxEntry::create(['slug' => $slug, 'type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored', 'realm' => 'site']);

        return $this->publish($component, $body);
    }

    private function publish(BeamUxEntry $entry, array $body): BeamUxEntry
    {
        $pub = app(EntryPublication::class);
        $pub->publishVersion($entry->fresh(), $pub->recordDraft($entry->fresh(), $body)->headVersion);

        return $entry->fresh();
    }

    private function entry(string $slug, string $type): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $slug, 'type' => $type, 'format' => 'tsx', 'realm' => 'site']);
    }

    private function node(string $name): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'dynamic' => false, 'props' => [], 'children' => []];
    }

    private function body(string $name, array $uses = []): array
    {
        return [array_replace($this->node('div'), ['children' => array_map($this->node(...), $uses), 'component' => ['name' => $name, 'props' => []]])];
    }
}
