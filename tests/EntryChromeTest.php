<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Containment\ChromeResolver;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Doctor\BeamUxChromeAudit;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * ADR-0213 — chrome is inherited down the containment tree, and nav grouping is a label rather than a
 * URL segment. Every case here is one the ADR argued for, against the REAL migration stub rather than
 * an inline test schema, because the whole feature is three columns and a schema that quietly lacked
 * one would make the rest of this file pass by accident.
 *
 *  - `layout` and `template` resolve from the **nearest declaring ancestor**, and the two axes resolve
 *    INDEPENDENTLY — `/docs/api` overriding its template must keep the layout `/docs` declared, which is
 *    the exact composition §4 exists to express;
 *  - the resolved names reach the payload, since the client cannot walk the tree;
 *  - `NavProjector` emits one **href-less** heading per distinct `nav_group` and leaves ungrouped
 *    children where they were — the compatibility half of §8, and the reason no URL moves;
 *  - the three fields round-trip disk → row → disk;
 *  - `BeamUxChromeAudit` fails a name that resolves to neither a registered component nor an entry.
 */
class EntryChromeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('beam_ux_entries');
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
    }

    public function test_the_shipped_migration_carries_the_three_chrome_columns(): void
    {
        $columns = Schema::getColumnListing('beam_ux_entries');

        $this->assertContains('layout', $columns);
        $this->assertContains('template', $columns);
        $this->assertContains('nav_group', $columns);
    }

    // ── inheritance (§4) ────────────────────────────────────────────────────────────────────

    public function test_each_axis_inherits_from_its_own_nearest_declaring_ancestor(): void
    {
        // The ADR's own worked example: `/docs` declares the layout once; `/docs/api` overrides ONLY
        // its template. Resolving both axes off the nearest row declaring EITHER would silently drop
        // the layout here, which is the failure the per-axis walk exists to prevent.
        $root = BeamUxEntry::rootFor();
        $docs = $this->page('docs', ['segment' => 'docs', 'parent_id' => $root->getKey(), 'layout' => 'DocsLayout', 'template' => 'ProseTemplate']);
        $api = $this->page('docs-api', ['segment' => 'api', 'parent_id' => $docs->getKey(), 'template' => 'SpreadTemplate']);
        $guide = $this->page('docs-guide', ['segment' => 'guide', 'parent_id' => $docs->getKey()]);

        $resolver = new ChromeResolver;

        $this->assertSame(
            ['layout' => 'DocsLayout', 'template' => 'SpreadTemplate'],
            $resolver->resolve([$root, $docs, $api]),
        );

        $this->assertSame(
            ['layout' => 'DocsLayout', 'template' => 'ProseTemplate'],
            $resolver->resolve([$root, $docs, $guide]),
        );
    }

    public function test_an_undeclared_chain_resolves_to_null_on_both_axes(): void
    {
        $root = BeamUxEntry::rootFor();
        $home = $this->page('home', ['segment' => '/', 'parent_id' => $root->getKey()]);

        $this->assertSame(
            ['layout' => null, 'template' => null],
            (new ChromeResolver)->resolve([$root, $home]),
        );
    }

    public function test_a_blank_declaration_inherits_rather_than_clearing(): void
    {
        // `''` is what a frontmatter key someone left blank and an empty form field both produce, and
        // "inherit" is the honest reading of a blank. Chrome has no `access: []`-style denial to
        // express, so there is deliberately no declared-but-empty state.
        $root = BeamUxEntry::rootFor();
        $docs = $this->page('docs', ['segment' => 'docs', 'parent_id' => $root->getKey(), 'layout' => 'DocsLayout']);
        $blank = $this->page('blank', ['segment' => 'blank', 'parent_id' => $docs->getKey(), 'layout' => '']);

        $this->assertSame('DocsLayout', (new ChromeResolver)->resolve([$root, $docs, $blank])['layout']);
    }

    public function test_the_renderer_payload_carries_the_resolved_names(): void
    {
        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);

        // The same recording renderer the sibling ADR-0209 test uses: a package test has no host page
        // to render, and the assertion here is about the PROPS the controller composes.
        $this->app->bind(EntryRenderer::class, fn () => new RecordingRenderer);

        Route::beamUxSite('site/entry');

        $root = BeamUxEntry::rootFor();
        $docs = $this->page('docs', ['segment' => 'docs', 'parent_id' => $root->getKey(), 'layout' => 'DocsLayout', 'template' => 'ProseTemplate']);
        $this->page('docs-api', ['segment' => 'api', 'parent_id' => $docs->getKey(), 'template' => 'SpreadTemplate']);

        $response = $this->get('/docs/api');

        $response->assertOk();
        $this->assertSame('DocsLayout', $response->json('props.entry.layout'));
        $this->assertSame('SpreadTemplate', $response->json('props.entry.template'));
    }

    // ── nav grouping (§8) ───────────────────────────────────────────────────────────────────

    public function test_nav_group_emits_one_href_less_heading_per_group_and_leaves_ungrouped_children_alone(): void
    {
        $root = BeamUxEntry::rootFor();
        $docs = $this->page('docs', ['segment' => 'docs', 'parent_id' => $root->getKey(), 'title' => 'Docs']);

        // Deliberately interleaved and out of declaration order, so the assertion proves the heading
        // lands at its FIRST member's position (i.e. inherits the nav_order the members were sorted by)
        // rather than at the end of the level.
        $this->page('overview', ['segment' => 'overview', 'parent_id' => $docs->getKey(), 'title' => 'Overview', 'nav_order' => 1]);
        $this->page('agents', ['segment' => 'agents', 'parent_id' => $docs->getKey(), 'title' => 'Agents', 'nav_order' => 2, 'nav_group' => 'Concepts']);
        $this->page('keys', ['segment' => 'keys', 'parent_id' => $docs->getKey(), 'title' => 'API keys', 'nav_order' => 3, 'nav_group' => 'Reference']);
        $this->page('blocks', ['segment' => 'blocks', 'parent_id' => $docs->getKey(), 'title' => 'Blocks', 'nav_order' => 4, 'nav_group' => 'Concepts']);

        $tree = $this->app->make(NavProjector::class)->project('site');

        $this->assertCount(1, $tree->items);
        $level = $tree->items[0]->children;

        $this->assertSame(['Overview', 'Concepts', 'Reference'], array_map(fn ($n) => $n->title, $level));

        // The ungrouped child keeps its href; the two headings have none — visible, ordered, and NOT
        // addressable, which is the whole distinction `nav_group` draws against a URL segment.
        $this->assertSame('/docs/overview', $level[0]->href);
        $this->assertNull($level[1]->href);
        $this->assertNull($level[2]->href);

        // Both members of a group hang off the one heading, in nav_order.
        $this->assertSame(['Agents', 'Blocks'], array_map(fn ($n) => $n->title, $level[1]->children));
        $this->assertSame(['/docs/agents', '/docs/blocks'], array_map(fn ($n) => $n->href, $level[1]->children));

        // …and no URL moved: grouping is nav-only.
        $this->assertSame('/docs/keys', $level[2]->children[0]->href);
    }

    public function test_a_tree_declaring_no_group_projects_exactly_as_before(): void
    {
        $root = BeamUxEntry::rootFor();
        $docs = $this->page('docs', ['segment' => 'docs', 'parent_id' => $root->getKey(), 'title' => 'Docs']);
        $this->page('one', ['segment' => 'one', 'parent_id' => $docs->getKey(), 'title' => 'One', 'nav_order' => 1]);
        $this->page('two', ['segment' => 'two', 'parent_id' => $docs->getKey(), 'title' => 'Two', 'nav_order' => 2]);

        $level = $this->app->make(NavProjector::class)->project('site')->items[0]->children;

        $this->assertSame(['One', 'Two'], array_map(fn ($n) => $n->title, $level));
        $this->assertSame(['/docs/one', '/docs/two'], array_map(fn ($n) => $n->href, $level));
    }

    // ── disk round trip ─────────────────────────────────────────────────────────────────────

    public function test_the_three_fields_import_from_frontmatter_and_survive_the_mirror_write(): void
    {
        // The batch writes through a registered StorageDriver; a recording fake keeps this a test of
        // the IMPORT rather than of beam-core's particle substrate, exactly as the sibling disk test does.
        $this->app->extend(
            StorageDriverResolver::class,
            fn () => (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, new RecordingDriver),
        );

        $root = sys_get_temp_dir().'/beamux-chrome-'.uniqid();
        mkdir($root.'/docs/page', 0777, true);

        $source = <<<'MDX'
        ---
        title: API keys
        segment: api-keys
        layout: DocsLayout
        template: ProseTemplate
        nav_group: Reference
        ---

        # API keys
        MDX;

        file_put_contents($root.'/docs/page/api-keys.mdx', $source);

        $this->app->make(RegisterEntriesFromDisk::class)->scan($root);

        $entry = BeamUxEntry::query()->where('slug', 'api-keys')->firstOrFail();

        $this->assertSame('DocsLayout', $entry->layout);
        $this->assertSame('ProseTemplate', $entry->template);
        $this->assertSame('Reference', $entry->nav_group);

        // The write-back leg. There is no column→frontmatter projection for ANY containment field —
        // the mirror decodes the BODY through the entry's codec, and `MdxBodyCodec` keeps the
        // frontmatter inside that body — so the round trip is real without a second authority to keep
        // in step. Asserted rather than assumed, because ticket 06's rule cuts both ways: a write path
        // with no reader is unverified, and so is a reader whose write path was never run.
        Storage::fake('mirror');
        $mirrorDisk = Storage::disk('mirror');

        (new PlacedDiskMirror($mirrorDisk))->mirror(
            $entry,
            'docs/page/api-keys.mdx',
            $entry->codec()->encode($source),
        );

        $written = $mirrorDisk->get('docs/page/api-keys.mdx');
        $this->assertStringContainsString('layout: DocsLayout', $written);
        $this->assertStringContainsString('template: ProseTemplate', $written);
        $this->assertStringContainsString('nav_group: Reference', $written);

        @unlink($root.'/docs/page/api-keys.mdx');
        @rmdir($root.'/docs/page');
        @rmdir($root.'/docs');
        @rmdir($root);
    }

    // ── the doctor check ────────────────────────────────────────────────────────────────────

    public function test_the_audit_passes_for_a_registered_name_and_for_a_name_that_is_an_entry(): void
    {
        config(['beam.ux.chrome.registered' => ['DocsLayout']]);

        $this->page('site-shell', ['type' => UxType::Layout]);
        $this->page('docs', ['segment' => 'docs', 'layout' => 'DocsLayout', 'template' => 'site-shell']);

        $findings = (new BeamUxChromeAudit)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_the_audit_fails_a_name_that_is_neither_registered_nor_an_entry(): void
    {
        config(['beam.ux.chrome.registered' => ['DocsLayout']]);

        $this->page('docs', ['segment' => 'docs', 'layout' => 'DcosLayout']);

        $findings = (new BeamUxChromeAudit)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('DcosLayout', $findings[0]->detail);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function page(string $slug, array $attributes = []): BeamUxEntry
    {
        return BeamUxEntry::create(array_merge([
            'slug' => $slug,
            'type' => UxType::Page,
            'format' => UxFormat::Mdx,
        ], $attributes));
    }
}
