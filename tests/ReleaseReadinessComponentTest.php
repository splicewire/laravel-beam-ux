<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseReadiness;

/**
 * ADR-0232 d11 (EP-18), the component items: a page that uses a component never published and not in the Release
 * blocks Publish, and the fix adds the component; a component the page uses that has a pending draft is a note, since
 * that draft could join the Release. Uses the native compiler, as the components' own publication proof does.
 */
class ReleaseReadinessComponentTest extends AuthoredComponentNativeTestCase
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

    public function test_a_never_published_component_blocks_with_the_fix_to_add_it(): void
    {
        $pub = app(EntryPublication::class);
        $card = $this->authoredComponent('card');
        $pub->recordDraft($card, $this->body('Card'));
        $page = BeamUxEntry::create(['slug' => 'uses-card', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $release = $this->releaseHolding($page, $pub->recordDraft($page, [$this->node('Card')])->headVersion);

        $report = app(ReleaseReadiness::class)->report($release);

        $this->assertFalse($report->ready);
        $this->assertCount(1, $report->blockers);
        $blocker = $report->blockers[0];
        $this->assertSame(['component', $page->id, $card->id, ['add', 'open']],
            [$blocker->code, $blocker->entryId, $blocker->componentId, $blocker->fixes]);
        $this->assertSame('Publish the component Card first, or include it in the same Release.', $blocker->message);
    }

    public function test_a_used_component_with_a_pending_draft_is_a_note_that_does_not_block(): void
    {
        $pub = app(EntryPublication::class);
        $card = $this->authoredComponent('card');
        $pub->publishVersion($card->fresh(), $pub->recordDraft($card, $this->body('Card'))->headVersion);
        $pub->recordDraft($card->fresh(), $this->body('Card', ['span']));
        $page = BeamUxEntry::create(['slug' => 'uses-card', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $release = $this->releaseHolding($page, $pub->recordDraft($page, [$this->node('Card')])->headVersion);

        $report = app(ReleaseReadiness::class)->report($release);

        $this->assertTrue($report->ready);
        $this->assertCount(1, $report->notes);
        $note = $report->notes[0];
        $this->assertSame(['component-draft', $page->id, $card->id, ['add']], [$note->code, $note->entryId, $note->componentId, $note->fixes]);
    }

    private function releaseHolding(BeamUxEntry $page, string $version): BeamUxRelease
    {
        $release = tap((new BeamUxRelease)->forceFill([
            'title' => 'Uses a card', 'realm' => 'site', 'created_by' => 'user:author', 'edited_by' => 'user:author',
            'status' => 'draft', 'origin' => 'built',
        ]))->save();
        $page->getConnection()->table('beam_ux_release_members')->insert([
            'id' => (string) Str::uuid(), 'release_id' => $release->id, 'entry_id' => $page->id, 'version_id' => $version,
            'observed_pin' => $page->fresh()->published_version, 'observed_marking' => $page->fresh()->workflow_marking,
        ]);

        return $release;
    }

    private function authoredComponent(string $slug): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $slug, 'type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored', 'realm' => 'site']);
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
