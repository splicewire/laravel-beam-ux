<?php

namespace Splicewire\Beam\Ux\Tests\Sync;

use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\Reasserter;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Tests\AuthoredComponentNativeTestCase;
use Splicewire\Sync\Contracts\SyncLineageResolver;

/**
 * EP-36 slice 1 (ADR-0227 d2; tower ADR-0008 d3; plan: launch EP36-SLICE-PLAN.md, decisions D1–D5). A BeamUxEntry is
 * laravel-beam-sync `Syncable` so a Scaffold Pack's `beam-ux-entry` kind can copy a Hosted site into a new tenant: only
 * the published version travels (D2), a copy is a `pack:<slug>` row born published with its asserted hash, and a
 * re-apply rewrites a copy only while it is pristine.
 */
class PackCopyTest extends AuthoredComponentNativeTestCase
{
    private const CONFIG = [BeamUxEntry::PACK_SLUG_CONFIG => 'starter-site'];

    protected function setUp(): void
    {
        parent::setUp();
        // Provenance (ADR-0215): `origin` and `asserted_hash`, which a pack copy stamps and a re-apply compares.
        (require dirname(__DIR__, 2).'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();
        // The default WriteGate delegates to the Laravel gate; a host grants its seed and sync actors their writes.
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
    }

    private function body(string $text, string $name = 'p', bool $component = false): array
    {
        return [['kind' => 'block', 'name' => $name, 'isComponent' => $component, 'dynamic' => false, 'props' => [],
            'children' => $component ? [] : [['kind' => 'text', 'value' => $text]]]];
    }

    private function published(string $slug, array $body, array $attributes = []): BeamUxEntry
    {
        return app(Reasserter::class)->seedBody(
            ['namespace' => null, 'slug' => $slug, 'realm' => 'site', 'type' => 'page'],
            ['title' => ucfirst($slug), 'format' => 'tsx', ...$attributes],
            $body,
            Provenance::package('splicewire/starter-fixture'),
        );
    }

    private function publishedBody(BeamUxEntry $entry): ?array
    {
        return app(EntryPublication::class)->publishedBodyOf($entry->fresh());
    }

    private function asCopy(array $payload, string $slug = 'copy'): array
    {
        $payload['coordinate']['slug'] = $slug; // one schema here, so the copy takes its own coordinate

        return $payload;
    }

    public function test_a_pack_copy_is_the_fourth_origin_and_ranks_with_a_package_stub(): void
    {
        $this->assertSame('pack:starter-site', Provenance::pack('starter-site'));
        $this->assertSame(Provenance::rank(Provenance::package('a/b')), Provenance::rank(Provenance::pack('starter-site')));
        $this->assertTrue(Provenance::outranks(Provenance::CMS, Provenance::pack('starter-site')));
    }

    public function test_only_the_published_version_travels_never_a_later_draft(): void
    {
        $source = $this->published('home', $this->body('Published'));
        app(EntryPublication::class)->recordDraft($source->fresh(), $this->body('Unreleased draft'));

        $payload = $source->fresh()->toSyncPayload();

        $this->assertSame($this->body('Published'), $payload['body']);
        $this->assertStringNotContainsString('Unreleased draft', json_encode($payload));
    }

    /**
     * Editor-parity ticket 20 (settled by ticket 17): a pack never carries a Hosted site's Site Settings. Its brand and its
     * GA4 measurement id are the tenant's own (laravel-beam-analytics ADR-0237 d6), so a copied id would put the source's
     * analytics property on every new site. A copied page keeps its SEO fields and Click Events (version and block fields).
     */
    public function test_a_copy_carries_no_analytics_id_or_brand_only_page_fields(): void
    {
        $source = $this->published('home', $this->body('Welcome'));
        $source->forceFill(['seo_description' => 'Start here'])->save();

        $payload = $source->fresh()->toSyncPayload();
        $keys = implode(' ', array_keys($payload['attributes']));

        $this->assertDoesNotMatchRegularExpression('/analytics|measurement|ga4|gtm|tracking|brand|logo|site_name/i', $keys);
        $this->assertArrayHasKey('seo_description', $payload['attributes']);
        $this->assertSame(['id', 'coordinate', 'attributes', 'parent_id', 'body'], array_keys($payload));
    }

    public function test_an_unpublished_entry_does_not_travel(): void
    {
        $draftOnly = BeamUxEntry::create(['slug' => 'draft-only', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);

        $this->expectException(\LogicException::class);
        $draftOnly->toSyncPayload();
    }

    public function test_a_copy_is_born_published_with_the_pack_origin_and_its_asserted_hash(): void
    {
        $source = $this->published('home', $this->body('Welcome'));
        $source->forceFill(['seo_description' => 'Start here', 'nav_order' => 2])->save();
        $payload = $this->asCopy($source->fresh()->toSyncPayload());

        $copy = BeamUxEntry::fromSyncPayload($payload, self::CONFIG);

        $this->assertSame('pack:starter-site', $copy->origin);
        $this->assertNotEmpty($copy->asserted_hash);
        $this->assertSame('Start here', $copy->seo_description);
        $this->assertSame(2, (int) $copy->nav_order);
        $this->assertSame($this->body('Welcome'), $this->publishedBody($copy));
    }

    public function test_a_re_apply_rewrites_a_pristine_copy_and_never_an_edited_one(): void
    {
        $payload = $this->asCopy($this->published('home', $this->body('Version one'))->fresh()->toSyncPayload());
        $pristine = BeamUxEntry::fromSyncPayload($payload, self::CONFIG);
        $edited = BeamUxEntry::fromSyncPayload($this->asCopy($payload, 'edited-copy'), self::CONFIG);
        app(EntryPublication::class)->recordDraft($edited->fresh(), $this->body('The tenant changed this'));
        app(EntryPublication::class)->publish($edited->fresh());

        $next = ['body' => $this->body('Version two')] + $payload;
        BeamUxEntry::fromSyncPayload($next, self::CONFIG);
        BeamUxEntry::fromSyncPayload($this->asCopy($next, 'edited-copy'), self::CONFIG);

        $this->assertSame($this->body('Version two'), $this->publishedBody($pristine));
        $this->assertSame($this->body('The tenant changed this'), $this->publishedBody($edited));
    }

    public function test_a_copy_needs_the_applying_packs_slug(): void
    {
        $payload = $this->asCopy($this->published('home', $this->body('Welcome'))->fresh()->toSyncPayload());

        $this->expectException(\InvalidArgumentException::class);
        BeamUxEntry::fromSyncPayload($payload, []);
    }

    public function test_the_parent_page_is_remapped_through_the_lineage_resolver(): void
    {
        $targetParent = $this->published('target-parent', $this->body('Parent'));
        $source = $this->published('child', $this->body('Child'), ['parent_id' => $this->published('source-parent', $this->body('P'))->getKey()]);
        $resolver = new class((string) $targetParent->getKey()) implements SyncLineageResolver
        {
            public function __construct(private string $target) {}

            public function resolveTargetId(string $class, string $sourceId): ?string
            {
                return $this->target;
            }
        };

        $copy = BeamUxEntry::fromSyncPayload($this->asCopy($source->fresh()->toSyncPayload()), [...self::CONFIG, SyncLineageResolver::CONFIG_KEY => $resolver]);

        $this->assertSame((string) $targetParent->getKey(), (string) $copy->parent_id);
    }

    public function test_dependencies_are_the_parent_and_the_authored_components_the_published_body_uses(): void
    {
        $parent = $this->published('parent', $this->body('Parent'));
        $component = BeamUxEntry::create(['slug' => 'hero', 'type' => 'component', 'format' => 'tsx', 'realm' => 'site']);
        $component->forceFill(['published_component_name' => 'Hero'])->save();
        $unused = BeamUxEntry::create(['slug' => 'footer', 'type' => 'component', 'format' => 'tsx', 'realm' => 'site']);
        $unused->forceFill(['published_component_name' => 'Footer'])->save();
        $page = $this->published('landing', $this->body('', 'Hero', component: true), ['parent_id' => $parent->getKey()]);

        $dependencies = $page->fresh()->syncDependencies()[BeamUxEntry::class] ?? [];

        $this->assertEqualsCanonicalizing([(string) $parent->getKey(), (string) $component->getKey()], $dependencies);
    }
}
