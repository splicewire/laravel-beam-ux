<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyInputData;
use Splicewire\Beam\Ux\JsonDoc\TsxLens;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;
use Splicewire\Beam\Ux\Particle\EntryBodyShowOp;
use Splicewire\Beam\Ux\Particle\EntryFormSaveOp;
use Splicewire\Beam\Ux\Particle\EntrySourceSaveOp;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowShowOp;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp;

/**
 * The entry-body transport as two particle operations (ADR-0214, beam-docs-satellite ticket 30).
 *
 * `handle()`/`respond()` are called directly rather than through `ParticleOperationController` — the
 * lighter tier `EntryWorkflowOpsTest` established, proving the op's own logic instead of re-testing
 * generic controller plumbing. What is asserted through the ATTRIBUTE instead is everything the
 * controller reads off the declaration and this test cannot otherwise reach: the kind, the shape slots,
 * and the authorization plane.
 *
 * The one thing this file deliberately does NOT re-prove is slug disambiguation. That was
 * `BeamUxEntryBodyControllerTest`'s whole subject and it is now unrepresentable: an operation is
 * addressed by `{id}` (ADR-0214 §2), so there is no ambiguous lookup left to tiebreak.
 */
class EntryBodyOpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_show_returns_the_declared_envelope_for_an_entry_with_no_particle_yet(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);

        $envelope = EntryBodyShowOp::handle($entry, new Request, actor: null);

        $this->assertInstanceOf(BeamUxEntryBodyData::class, $envelope);
        $this->assertSame((string) $entry->id, $envelope->id);
        $this->assertSame('about', $envelope->slug);
        $this->assertSame('page', $envelope->type);
        $this->assertSame([], $envelope->body);
        $this->assertNull($envelope->compileError);
    }

    public function test_body_envelope_serves_the_same_profile_as_the_save_guard(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'profile', 'type' => 'page', 'format' => 'tsx']);
        $resolver = app(\Splicewire\Beam\Ux\Manifest\ManifestProfileResolver::class);
        $envelope = EntryBodyShowOp::handle($entry, new Request, actor: null);
        $this->assertSame($resolver->resolve($entry), $envelope->profile);
        $this->assertTrue($envelope->profile['x-admits-code']);

        $this->bindSplitTenancy();
        $tenantEnvelope = EntryBodyShowOp::handle($entry, new Request, actor: null);
        $this->assertSame(app(\Splicewire\Beam\Ux\Manifest\ManifestProfileResolver::class)->resolve($entry), $tenantEnvelope->profile);
        $this->assertFalse($tenantEnvelope->profile['x-admits-code']);
    }

    public function test_a_theme_entry_falls_back_to_the_theme_schemas_when_it_declares_no_schema_ref(): void
    {
        // The one non-trivial branch of the schema resolution the controller carried: a `theme` entry's
        // body is a `{canvas,site}` token object, not a component's inferred props, so it has no
        // `schema_ref` and would otherwise seed the SchemaForm with nothing.
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'namespace' => 'theme']);

        $envelope = EntryBodyShowOp::handle($theme, new Request, actor: null);

        $this->assertIsArray($envelope->schema);
        $this->assertSame('Theme', $envelope->schema['title']);
        $this->assertSame(ThemeSchemas::canvas(), $envelope->schema['properties']['canvas']);
        $this->assertSame(ThemeSchemas::site(), $envelope->schema['properties']['site']);

        // The theme editor is Frame's form over this schema, so the dark variant's slots ARE its fields.
        $site = $envelope->schema['properties']['site']['properties'];
        $this->assertArrayHasKey('darkBackground', $site);
        $this->assertArrayHasKey('darkAccent', $site);
    }

    public function test_an_inline_schema_ref_is_surfaced_by_value_over_the_theme_fallback(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'hero',
            'type' => 'component',
            'schema_ref' => json_encode(['type' => 'object', 'title' => 'Hero']),
        ]);

        $this->assertSame('Hero', EntryBodyShowOp::handle($entry, new Request, actor: null)->schema['title']);
    }

    public function test_save_persists_the_body_binds_a_first_write_particle_id_and_reads_it_back(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);
        $this->assertNull($entry->particle_id);

        $request = Request::create('/beam-ux-entries/'.$entry->id.'/save-body', 'POST', [
            'body' => ['kind' => 'doc', 'children' => [['kind' => 'text', 'value' => 'hello']]],
        ]);

        $payload = EntryBodySaveOp::handle($entry, $request, actor: null);

        // The first-write binding is the step that makes the NEXT read find anything — an entry created
        // by `ScaffoldCommand` / `RegisterEntriesFromDisk` / a test has no particle until a save mints one.
        $this->assertNotSame('', $payload['key']);
        $this->assertSame($payload['key'], (string) $entry->fresh()->particle_id);

        $envelope = EntryBodySaveOp::respond($payload, $entry);

        $this->assertInstanceOf(BeamUxEntryBodyData::class, $envelope);
        $this->assertSame('hello', $envelope->body['children'][0]['value']);
        $this->assertSame((string) $entry->id, $envelope->id);
    }

    public function test_a_second_save_writes_to_the_bound_particle_rather_than_minting_another(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);

        $first = EntryBodySaveOp::handle($entry, $this->saveRequest($entry, ['v' => 1]), actor: null);
        $second = EntryBodySaveOp::handle($entry->fresh(), $this->saveRequest($entry, ['v' => 2]), actor: null);

        $this->assertSame($first['key'], $second['key']);
        $this->assertSame(2, EntryBodySaveOp::respond($second, $entry->fresh())->body['v']);
    }

    public function test_the_read_op_round_trips_what_the_write_op_saved(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);

        EntryBodySaveOp::handle($entry, $this->saveRequest($entry, ['v' => 7]), actor: null);

        $this->assertSame(7, EntryBodyShowOp::handle($entry->fresh(), new Request, actor: null)->body['v']);
    }

    public function test_save_refuses_a_json_doc_body_on_an_mdx_entry_and_leaves_the_source_intact(): void
    {
        // Measured on beam.test 2026-09-11 (G2-BEAM-AUTHOR-ENTRY, SEVERE): the canvas editor opened on
        // the mdx `/docs` entry and its Save wrote a JsonNode[] list over the `{frontmatter,content}`
        // particle payload. `MdxBodyCodec::decode()` has no content key to read in that shape, so the
        // disk mirror wrote 0 bytes and the compile produced an empty artifact — the public page went
        // blank for every visitor. The format axis is the entry's, not the payload's: a body language
        // that cannot carry a JsonDoc must refuse one rather than store it and lose the source.
        $entry = BeamUxEntry::create(['slug' => 'docs', 'type' => 'page', 'format' => 'mdx', 'namespace' => null]);
        EntryBodySaveOp::handle($entry, $this->saveRequest($entry, MdxBody::encode("# Docs\n\nReal content.")), actor: null);
        $particleId = (string) $entry->fresh()->particle_id;

        try {
            EntryBodySaveOp::handle($entry->fresh(), $this->saveRequest($entry, [
                ['kind' => 'block', 'tag' => 'div', 'props' => ['className' => 'page'], 'children' => []],
            ]), actor: null);
            $this->fail('a JsonDoc body was accepted on an mdx entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
            $this->assertStringContainsString('mdx', $e->errors()['body'][0]);
        }

        // The refusal is worth nothing if the write already landed: re-read the particle.
        $this->assertSame($particleId, (string) $entry->fresh()->particle_id);
        $envelope = EntryBodyShowOp::handle($entry->fresh(), new Request, actor: null);
        $this->assertStringContainsString('Real content.', (string) $envelope->body[MdxBody::CONTENT_KEY]);
    }

    public function test_save_accepts_a_json_doc_body_on_a_tsx_entry_because_its_codec_can_carry_one(): void
    {
        // The other half of the guard: tsx is the ONE format whose codec prints a JsonNode[] list back
        // to source (`TsxBodyCodec::decode()`'s `array_is_list` branch), so the canvas may save onto it.
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        $payload = EntryBodySaveOp::handle($entry, $this->saveRequest($entry, [
            ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Authored']]],
        ]), actor: null);

        $this->assertSame('h2', EntryBodySaveOp::respond($payload, $entry->fresh())->body[0]['name']);
    }

    public function test_save_refuses_a_canvas_body_with_a_block_outside_the_manifest_profile(): void
    {
        // EP-07a c (ADR-0228 d8): the save checks each node against the realm's resolved Manifest
        // Profile. A tag the profile does not ship (<Marquee>) is refused with a 422 on `body` naming
        // the node by id + path — proving ProfileLegality runs through the real op, not just in isolation.
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        try {
            EntryBodySaveOp::handle($entry, $this->saveRequest($entry, [
                ['kind' => 'block', 'name' => 'Marquee', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [], 'id' => 'm1'],
            ]), actor: null);
            $this->fail('expected a ValidationException for an out-of-profile block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Marquee', implode("\n", $e->errors()['body']));
            $this->assertStringContainsString('m1', implode("\n", $e->errors()['body']));
        }
    }

    private function bindSplitTenancy(string $central = 'central_db'): void
    {
        $this->app->instance(TenancyContext::class, new class($central) implements TenancyContext
        {
            public function __construct(private string $central) {}

            public function isMultiTenant(): bool { return true; }

            public function isActive(): bool { return true; }

            public function currentTenant(): ?object { return null; }

            public function currentTenantId(): int|string|null { return 1; }

            public function centralConnection(): ?string { return $this->central; }
        });
    }

    public function test_save_body_refuses_code_on_a_tenant_resident_entry_even_unchanged(): void
    {
        // ADR-0227 d1: a tenant-owned entry holds blocks, not code — an opaque node (verbatim code) is
        // refused on a tenant save, whole-body (EP-06 d8's narrowing never spares code). Residency is the
        // entry's connection; the refusal fires before the write, so no tenant DB is needed.
        $this->bindSplitTenancy();
        $entry = BeamUxEntry::create(['slug' => 'tenant-code', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        $entry->setConnection('tenant_acme');

        try {
            EntryBodySaveOp::handle($entry, $this->saveRequest($entry, [
                ['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}'],
            ]), actor: null);
            $this->fail('a tenant-resident entry accepted an opaque (code) node');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('code', implode("\n", $e->errors()['body']));
        }
    }

    public function test_save_source_refuses_on_a_tenant_resident_entry(): void
    {
        // ADR-0227 d1 / ADR-0233 d6: source (MDX/TSX) is code; a tenant-owned entry refuses a source write.
        $this->bindSplitTenancy();
        $entry = BeamUxEntry::create(['slug' => 'tenant-src', 'type' => 'component', 'format' => 'tsx', 'namespace' => null]);
        $entry->setConnection('tenant_acme');

        try {
            EntrySourceSaveOp::handle(
                $entry,
                Request::create('/beam-ux-entries/'.$entry->id.'/save-source', 'POST', ['source' => 'export const X = () => null']),
                actor: null,
            );
            $this->fail('a tenant-resident entry accepted a source write');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source', $e->errors());
        }
    }

    /**
     * ADR-0227 d1 — a body that is not a canvas document is source, and source is code. The guard read only
     * a JsonDoc-shaped body, so `{source: '<tsx>'}` (the disk-registered shape `TsxBodyCodec::decode()`
     * compiles verbatim) reached a tenant page untouched (EP-07a probe P2, launch 2026-10-17). The entry is
     * on `testing` while the central connection is `central_db`, so it is tenant-resident.
     */
    public function test_save_body_refuses_a_non_canvas_body_on_a_tenant_resident_entry(): void
    {
        $this->bindSplitTenancy();
        $cases = [
            'tsx page, {source}' => [['slug' => 'p', 'type' => 'page', 'format' => 'tsx'], ['source' => 'export default function Page() { steal(); return null; }']],
            'component, {source}' => [['slug' => 'c', 'type' => 'component', 'format' => 'tsx'], ['source' => 'export default function C() { steal(); return null; }']],
            'mdx page, {content}' => [['slug' => 'm', 'type' => 'page', 'format' => 'mdx'], ['frontmatter' => [], 'content' => "# Hi\n\n{steal()}\n"]],
        ];

        foreach ($cases as $label => [$attributes, $body]) {
            $entry = BeamUxEntry::create([...$attributes, 'namespace' => null])->fresh();

            try {
                EntryBodySaveOp::handle($entry, $this->saveRequest($entry, $body), actor: null);
                $this->fail("$label: a tenant-resident entry accepted a non-canvas body");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('code', implode("\n", $e->errors()['body']), $label);
            }

            $this->assertNull($entry->fresh()->particle_id, "$label: the refused body was written");
        }
    }

    public function test_save_body_refuses_a_literal_prop_that_would_print_code_in_every_realm(): void
    {
        // EP-07a probe P3/P4: a literal prop's name, or a `number` prop's value, printed into the module raw.
        // Refused at save on a CENTRAL entry too: a literal is never the way code is written.
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        $props = [
            'a name' => ['name' => 'onClick={() => steal()} data-x', 'kind' => 'boolean-shorthand', 'value' => true],
            'a number value' => ['name' => 'data-n', 'kind' => 'number', 'value' => '0} onMouseOver={() => steal()} data-m={0'],
        ];

        foreach ($props as $label => $prop) {
            try {
                EntryBodySaveOp::handle($entry, $this->saveRequest($entry, [
                    ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [$prop], 'children' => [['kind' => 'text', 'value' => 'x']]],
                ]), actor: null);
                $this->fail("$label carrying code was saved");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors(), $label);
            }
        }

        $this->assertNull($entry->fresh()->particle_id, 'a refused body was written');
    }

    public function test_a_tenant_resident_entry_may_still_clear_its_body(): void
    {
        $this->bindSplitTenancy();
        $entry = BeamUxEntry::create(['slug' => 'p', 'type' => 'page', 'format' => 'tsx', 'namespace' => null])->fresh();

        app(\Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard::class)->assertAdmitted($entry, []);
        $this->addToAssertionCount(1);
    }

    public function test_a_tenant_resident_theme_keeps_its_form_body(): void
    {
        // A theme is Form-kind: its `{canvas,site}` token object is data, not source (ADR-0228 d1), and a
        // Hosted site's theme is a tenant row (ADR-0227 d3), so the code refusal must not reach it.
        $this->bindSplitTenancy();
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'format' => 'css', 'namespace' => 'theme'])->fresh();

        app(\Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard::class)->assertAdmitted($theme, ['canvas' => [], 'site' => ['accent' => '#123456']]);
        $this->addToAssertionCount(1);
    }

    public function test_a_central_entry_keeps_the_disk_registered_source_shape(): void
    {
        // Central entries are unchanged (ADR-0227 d1): `{source}` is how RegisterEntriesFromDisk stores a .tsx.
        $entry = BeamUxEntry::create(['slug' => 'p', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        $payload = EntryBodySaveOp::handle($entry, $this->saveRequest($entry, ['source' => 'export default function Page() { return null; }']), actor: null);

        $this->assertSame($payload['key'], (string) $entry->fresh()->particle_id);
    }

    public function test_restore_refuses_a_non_canvas_version_on_a_tenant_resident_entry(): void
    {
        // History written while the entry was central-authored (or before this guard) must not be a way to
        // bring source back into a tenant page.
        $entry = BeamUxEntry::create(['slug' => 'p', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->write($entry, ['source' => 'export default function Page() { steal(); return null; }']);
        $version = app(\Rushing\Versioning\Contracts\VersionStore::class)
            ->snapshot(\Splicewire\Beam\Models\BeamParticle::find($entry->fresh()->particle_id), 'source');

        // Move the working copy on, so a restore would visibly change it.
        $this->setWorkingBody($entry, [$this->node('p', 'now')]);
        $this->bindSplitTenancy();
        $publication = app(\Splicewire\Beam\Ux\Publish\EntryPublication::class);
        $head = $publication->state($entry->fresh())->headVersion;

        try {
            $publication->restore($entry->fresh(), (string) $version->id);
            $this->fail('a tenant-resident entry restored a source version');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('code', implode("\n", $e->errors()['body']));
        }

        // Refused before any write: the entry is as it was (ADR-0228 d9 as amended).
        $this->assertSame($head, $publication->state($entry->fresh())->headVersion);
        $this->assertSame('now', $this->workingBody($entry)[0]['children'][0]['value']);
    }

    public function test_restore_refuses_a_canvas_version_carrying_code_on_a_tenant_resident_entry(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'p', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        $version = $this->versionWithBody($entry, [['kind' => 'opaque', 'reason' => 'expression', 'source' => '{steal()}']]);
        $this->setWorkingBody($entry, [$this->node('p', 'now')]);
        $this->bindSplitTenancy();

        try {
            app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->restore($entry->fresh(), (string) $version->id);
            $this->fail('a tenant-resident entry restored an opaque (code) node');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opaque', implode("\n", $e->errors()['body']));
        }

        $this->assertSame('now', $this->workingBody($entry)[0]['children'][0]['value']);
    }

    /**
     * ADR-0228 d9: restore never refuses ILLEGALITY, because it is not authoring. A version holding an unknown
     * block is restored as the draft, with the illegality as a diagnostic; Publish, which serves it, refuses.
     */
    public function test_restore_stores_an_illegal_code_free_version_with_diagnostics_and_publish_refuses_it(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'p', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        $version = $this->versionWithBody($entry, [['kind' => 'block', 'id' => 'mq', 'name' => 'marquee', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]]);
        $this->setWorkingBody($entry, [$this->node('p', 'now')]);
        $this->bindSplitTenancy();
        $publication = app(\Splicewire\Beam\Ux\Publish\EntryPublication::class);

        $state = $publication->restore($entry->fresh(), (string) $version->id);

        $this->assertSame('marquee', $this->workingBody($entry)[0]['name'], 'the illegal version was not restored');
        $this->assertTrue($state->draftPending);
        $this->assertStringContainsString('marquee', implode("\n", $state->diagnostics));
        $this->assertStringContainsString('#mq', implode("\n", $state->diagnostics));

        try {
            $publication->publish($entry->fresh());
            $this->fail('an illegal restored draft was published');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('marquee', implode("\n", $e->errors()['body']));
        }
    }

    /** A canvas text-leaf node. */
    private function node(string $tag, string $text): array
    {
        return ['kind' => 'block', 'id' => 'n-'.$text, 'name' => $tag, 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => $text]]];
    }

    /** Record a version holding `$body` exactly, past the save check, as history written before it existed. */
    private function versionWithBody(BeamUxEntry $entry, array $body): \Rushing\Versioning\Models\Version
    {
        if ($entry->fresh()->particle_id === null) {
            app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->write($entry, [$this->node('p', 'first')]);
        }
        $this->setWorkingBody($entry, $body);

        return app(\Rushing\Versioning\Contracts\VersionStore::class)
            ->snapshot(\Splicewire\Beam\Models\BeamParticle::find($entry->fresh()->particle_id), 'history');
    }

    private function setWorkingBody(BeamUxEntry $entry, array $body): void
    {
        $particle = \Splicewire\Beam\Models\BeamParticle::find($entry->fresh()->particle_id);
        $particle->forceFill(['payload' => $body])->save();
        app(\Rushing\Versioning\Contracts\VersionStore::class)->snapshot($particle->fresh(), 'working');
    }

    private function workingBody(BeamUxEntry $entry): array
    {
        return app(\Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard::class)->workingHead($entry->fresh());
    }

    public function test_a_css_theme_entry_also_refuses_a_json_doc_body(): void
    {
        // The guard is a codec CAPABILITY (`AcceptsJsonDoc`), not an mdx special case — every format
        // that cannot carry a JsonDoc refuses one, including a host's own bespoke `UxFormatCase`.
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'format' => 'css', 'namespace' => 'theme']);

        $this->expectException(ValidationException::class);

        EntryBodySaveOp::handle($theme, $this->saveRequest($theme, [
            ['kind' => 'block', 'tag' => 'div', 'children' => []],
        ]), actor: null);
    }

    public function test_a_mixed_block_text_opaque_json_doc_hydrates_through_the_declared_union(): void
    {
        // ADR-0228 d2: save-body hydrates the canvas body through the JsonNode union, so a well-formed
        // document carrying every arm — a block with a prop and nested children, a text leaf, and a
        // sealed opaque island — saves cleanly onto a codec that accepts one.
        $entry = BeamUxEntry::create(['slug' => 'mixed', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        $doc = [[
            'kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false,
            'props' => [['name' => 'id', 'kind' => 'string', 'value' => 'hero']],
            'children' => [
                ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Title']]],
                ['kind' => 'opaque', 'reason' => 'map', 'source' => '{items.map((i) => <li>{i}</li>)}'],
            ],
        ]];

        $payload = EntryBodySaveOp::handle($entry, $this->saveRequest($entry, $doc), actor: null);

        $this->assertSame('section', EntryBodySaveOp::respond($payload, $entry->fresh())->body[0]['name']);
    }

    public function test_save_refuses_a_json_doc_node_with_an_unknown_kind_as_a_422_not_a_500(): void
    {
        // ADR-0228 d2: a node whose `kind` is not an arm of the union is a malformed canvas document. The
        // refusal must be the same clean 422 the wire gives, never an uncaught 500 (the hydrator's
        // CannotCreateAbstractClass), and nothing may be written.
        $entry = BeamUxEntry::create(['slug' => 'bad-kind', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        try {
            EntryBodySaveOp::handle($entry, $this->saveRequest($entry, [
                ['kind' => 'wormhole', 'name' => 'div', 'children' => []],
            ]), actor: null);
            $this->fail('a node with an unknown kind was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }

        $this->assertNull($entry->fresh()->particle_id);
    }

    public function test_save_refuses_a_malformed_json_doc_node_as_a_422_not_a_500(): void
    {
        // ADR-0228 d2: a node of a known kind but a broken shape (a block whose `children` is not a list
        // of nodes) is refused the same way — the hydrator's type error becomes a 422, not a 500.
        $entry = BeamUxEntry::create(['slug' => 'bad-shape', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        try {
            EntryBodySaveOp::handle($entry, $this->saveRequest($entry, [
                ['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => 'not-a-list'],
            ]), actor: null);
            $this->fail('a malformed node was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }

        $this->assertNull($entry->fresh()->particle_id);
    }

    public function test_the_envelope_carries_the_format_and_the_decoded_source_for_a_non_json_doc_body(): void
    {
        // The client half of the same defect: the dock offered the canvas on an mdx entry because the
        // read op's envelope never said what body language the entry speaks. `format` is that answer,
        // and `source` is what a source editor needs — the canvas body is NOT decoded into it (a tsx
        // JsonDoc's source is the canvas's own to print), so `source === null` means "this IS a canvas
        // document" and a string means "this is source text".
        $mdx = BeamUxEntry::create(['slug' => 'docs', 'type' => 'page', 'format' => 'mdx', 'namespace' => null]);
        EntryBodySaveOp::handle($mdx, $this->saveRequest($mdx, MdxBody::encode("---\ntitle: Docs\n---\n# Docs")), actor: null);

        $envelope = EntryBodyShowOp::handle($mdx->fresh(), new Request, actor: null);
        $this->assertSame('mdx', $envelope->format);
        $this->assertStringContainsString('# Docs', (string) $envelope->source);
        $this->assertStringContainsString('title: Docs', (string) $envelope->source);

        $tsx = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        EntryBodySaveOp::handle($tsx, $this->saveRequest($tsx, [
            ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Hi']]],
        ]), actor: null);

        $canvas = EntryBodyShowOp::handle($tsx->fresh(), new Request, actor: null);
        $this->assertSame('tsx', $canvas->format);
        $this->assertNull($canvas->source);
    }

    public function test_an_entry_with_no_body_yet_reports_its_format_and_no_source(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        $envelope = EntryBodyShowOp::handle($entry, new Request, actor: null);

        $this->assertSame('tsx', $envelope->format);
        $this->assertNull($envelope->source);
    }

    public function test_a_canvas_authored_body_compiles_from_a_module_not_from_bare_jsx_statements(): void
    {
        // G2-BEAM-AUTHOR-ENTRY, measured on beam.test 2026-09-11. The disk mirror's source
        // (`TsxBodyCodec::decode()` -> `JsonDocPrinter::print()`) is bare JSX STATEMENTS, because
        // `blockdoc`'s `parse()` has to read that file back. esbuild compiles those to a module that
        // exports nothing - so the owner's authored `/` served a 200 artifact with no `default`, and
        // `<EntryBody>` rendered "not compiled yet" over a body that had compiled fine. The compiler
        // gets the MODULE form; the mirror keeps the statements.
        $entry = BeamUxEntry::create(['slug' => 'canvas-home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        $node = ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Authored']]];

        EntryBodySaveOp::handle($entry, Request::create('/x', 'POST', ['body' => [$node]]), actor: null);

        $source = (string) $this->app->make(CompileEntryBody::class)->sourceFor($entry->fresh());

        $this->assertStringContainsString('export default function Page()', $source);
        $this->assertStringContainsString('<h2>Authored</h2>', $source);

        // The MIRROR is unchanged: its consumer is a re-parseable file, not a module.
        $this->assertSame('<h2>Authored</h2>;', $entry->fresh()->codec()->decode([$node]));
    }

    public function test_a_file_authored_tsx_body_compiles_from_its_own_source_untouched(): void
    {
        // The other branch: a `{source: ...}` body is a real module already and must not be re-wrapped.
        $entry = BeamUxEntry::create(['slug' => 'file-home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        EntryBodySaveOp::handle($entry, Request::create('/x', 'POST', [
            'body' => $entry->codec()->encode('export default function Page() { return <div>File</div>; }'),
        ]), actor: null);

        $source = (string) $this->app->make(CompileEntryBody::class)->sourceFor($entry->fresh());

        $this->assertStringContainsString('<div>File</div>', $source);
        $this->assertSame(1, substr_count($source, 'export default'));
    }

    public function test_the_declared_input_rejects_a_payload_that_is_not_a_body(): void
    {
        // The endpoint this replaces read `(array) $request->input('body', [])`, so a client that sent
        // `{ "bodyy": … }` got a silent save of an empty document. The declaration is what turns that
        // into a 422 — and `ParticleOperationController::validateInput()` runs it before `handle()`.
        $this->expectException(ValidationException::class);

        BeamUxEntryBodyInputData::validate(['bodyy' => ['nope']]);
    }

    public function test_an_empty_body_is_accepted_because_clearing_a_document_is_a_legitimate_edit(): void
    {
        // `present`, not `required` — `required` rejects `[]`, and an author emptying a page is normal.
        $this->assertSame([], BeamUxEntryBodyInputData::validateAndCreate(['body' => []])->body);
    }

    public function test_both_operations_declare_their_kind_shapes_and_authorization_plane(): void
    {
        $show = $this->attributeOn(EntryBodyShowOp::class);
        $save = $this->attributeOn(EntryBodySaveOp::class);

        $this->assertSame('beam-ux-entry', $show->resource);
        $this->assertSame('body', $show->name);
        $this->assertSame(OperationKind::Read, $show->kind);
        $this->assertSame(BeamUxEntryBodyData::class, $show->output);
        // A GET operation takes no request body, and `false` is the DECLARED "accepts nothing" state —
        // distinct from `null`, which only means nobody has decided yet.
        $this->assertFalse($show->input);

        $this->assertSame('save-body', $save->name);
        $this->assertSame(OperationKind::Write, $save->kind);
        $this->assertSame(BeamUxEntryBodyInputData::class, $save->input);
        $this->assertSame(BeamUxEntryBodyData::class, $save->output);

        foreach ([$show, $save] as $operation) {
            $this->assertSame('ux.author', $operation->ability);
            // `ux.author` is an ENTITLEMENT key. `false` routes the check to the subject-free
            // entitlement plane; leaving it null hands the loaded entry to `AbilityResolver` and asks a
            // per-action question with a token that has no per-action meaning
            // (particle-operation-surface ticket 08).
            $this->assertFalse($operation->abilityModel);
        }
    }

    public function test_the_package_registers_all_four_of_its_operations_without_a_host_naming_a_class(): void
    {
        // ADR-0214 §5. Before this, a beam-ux operation reached the registry only because a HOST listed
        // its FQCN in `beam.core.particle.classes` — the inverse of ADR-0210's "beam-core never learns a
        // consumer's name". The testbench app names none of them, so anything found here was registered
        // by the package's own `packageBooted()`.
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $this->assertEmpty(config('beam.core.particle.classes', []));

        foreach (['body', 'save-body', 'workflow', 'transition'] as $name) {
            $this->assertNotNull(
                $registry->find('beam-ux-entry', $name),
                "beam-ux did not register its own `beam-ux-entry.{$name}` operation.",
            );
        }
    }

    public function test_the_registered_operations_are_the_declaring_classes_themselves(): void
    {
        // Guards against the registration list drifting into a second, hand-built reader of the
        // attributes: what registers must be the annotated classes, not a copy of what they say.
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $this->assertSame(
            BeamUxEntryBodyData::class,
            $registry->find('beam-ux-entry', 'body')->output,
        );
        $this->assertSame(
            EntryWorkflowTransitionOp::class,
            $this->attributeClassFor($registry, 'transition'),
        );
        $this->assertSame(
            EntryWorkflowShowOp::class,
            $this->attributeClassFor($registry, 'workflow'),
        );
    }

    /** @param array<string, mixed> $body */
    public function test_clearing_a_body_to_an_empty_document_retires_its_artifact_and_reads_as_no_body(): void
    {
        // Measured 2026-09-12 on beam.test: the G2 authoring probe restored `/` to `[]`, the save compiled
        // an artifact that exported nothing, and every guest was told to run an artisan command over a
        // page whose honest state is "nothing authored yet". Clearing is a legal act; it must land on
        // the unauthored reader state, not on a stale or empty module.
        $entry = BeamUxEntry::create(['slug' => 'cleared-home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        $node = ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Authored']]];
        $artifacts = $this->app->make(EntryArtifactStore::class);

        EntryBodySaveOp::handle($entry, Request::create('/x', 'POST', ['body' => [$node]]), actor: null);
        // The Node compiler is not part of this suite; stand the authored artifact up the way the
        // compile step would have, so the clearing save has something real to retire.
        $artifacts->put($entry->fresh(), 'export default () => null');
        $this->assertTrue($artifacts->has($entry->fresh()));

        $payload = EntryBodySaveOp::handle($entry->fresh(), Request::create('/x', 'POST', ['body' => []]), actor: null);

        $this->assertNull($payload['compileError'], 'clearing a document is not a compile failure');
        $this->assertFalse($artifacts->has($entry->fresh()), 'an empty body leaves no artifact behind');
        $this->assertNull($this->app->make(CompileEntryBody::class)->sourceFor($entry->fresh()));
        $this->assertSame([], EntryBodyShowOp::handle($entry->fresh(), new Request, actor: null)->body);
    }

    public function test_save_source_writes_the_encoded_source_on_a_source_kind_entry_and_round_trips(): void
    {
        // ADR-0228 d10 Source input: a Source-kind entry (mdx) is authored as text; the `{ source }` write
        // runs it through the codec and stores the body, and the read op decodes it straight back.
        $entry = BeamUxEntry::create(['slug' => 'docs', 'type' => 'page', 'format' => 'mdx', 'namespace' => null]);

        $payload = EntrySourceSaveOp::handle(
            $entry,
            Request::create('/beam-ux-entries/'.$entry->id.'/save-source', 'POST', ['source' => "---\ntitle: Docs\n---\n# Docs\n\nReal source."]),
            actor: null,
        );

        $this->assertNotSame('', $payload['key']);
        $envelope = EntryBodyShowOp::handle($entry->fresh(), new Request, actor: null);
        $this->assertSame('mdx', $envelope->format);
        $this->assertStringContainsString('Real source.', (string) $envelope->source);
    }

    public function test_save_source_on_a_tsx_entry_lowers_the_source_to_a_stored_jsondoc(): void
    {
        // EP-06 d9: a Blocks-kind (tsx) entry's source write is LOWERED to a JsonDoc through the Node TSX
        // Lens (the EP-05 refusal became the lowering). A fake lens stands in for the Node run, proven
        // end-to-end on tots in docs/review/ep-06-node-identity-20261017/d9-real-lens-run.md.
        $this->app->bind(TsxLens::class, fn () => new class implements TsxLens
        {
            public function lower(string $source): array
            {
                return [['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Lowered']], 'dynamic' => false]];
            }
        });

        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        $payload = EntrySourceSaveOp::handle(
            $entry,
            Request::create('/beam-ux-entries/'.$entry->id.'/save-source', 'POST', ['source' => 'export default () => <h2>Lowered</h2>']),
            actor: null,
        );

        $stored = EntrySourceSaveOp::respond($payload, $entry->fresh())->body;
        $this->assertSame('h2', $stored[0]['name']);
        $this->assertArrayHasKey('id', $stored[0]); // a stable id was minted by the rematch
    }

    public function test_save_source_refuses_a_form_kind_entry_that_has_no_source_editor(): void
    {
        // A theme is Form-kind: it has no source editor, so a source write is refused.
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'format' => 'css', 'namespace' => 'theme']);

        try {
            EntrySourceSaveOp::handle(
                $theme,
                Request::create('/beam-ux-entries/'.$theme->id.'/save-source', 'POST', ['source' => 'whatever']),
                actor: null,
            );
            $this->fail('a source write was accepted on a form-kind entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source', $e->errors());
        }

        $this->assertNull($theme->fresh()->particle_id);
    }

    public function test_save_form_stores_a_theme_form_checked_against_its_schema_and_round_trips(): void
    {
        // ADR-0228 d10 Form input: a theme's `{ canvas, site }` fields, checked against the entry's schema,
        // stored as the body (CssBodyCodec decodes it to custom properties on the mirror).
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'format' => 'css', 'namespace' => 'theme']);

        $payload = EntryFormSaveOp::handle(
            $theme,
            Request::create('/beam-ux-entries/'.$theme->id.'/save-form', 'POST', ['form' => ['canvas' => ['accent' => '#14803f'], 'site' => []]]),
            actor: null,
        );

        $this->assertNotSame('', $payload['key']);
        $envelope = EntryFormSaveOp::respond($payload, $theme->fresh());
        $this->assertSame('#14803f', $envelope->body['canvas']['accent']);
    }

    public function test_save_form_refuses_a_field_the_schema_does_not_declare(): void
    {
        // The theme schema's canvas is `additionalProperties: false`, so an undeclared field is a 422 on
        // `form`, before anything is written.
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'format' => 'css', 'namespace' => 'theme']);

        try {
            EntryFormSaveOp::handle(
                $theme,
                Request::create('/x', 'POST', ['form' => ['canvas' => ['notARealThemeField' => 'x']]]),
                actor: null,
            );
            $this->fail('a form field the schema does not declare was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('form', $e->errors());
        }

        $this->assertNull($theme->fresh()->particle_id);
    }

    public function test_save_form_refuses_a_non_form_entry_naming_its_kind(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);

        try {
            EntryFormSaveOp::handle($entry, Request::create('/x', 'POST', ['form' => ['canvas' => []]]), actor: null);
            $this->fail('a form write was accepted on a blocks entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('form', $e->errors());
            $this->assertStringContainsString('blocks', $e->errors()['form'][0]);
        }
    }

    private function saveRequest(BeamUxEntry $entry, array $body): Request
    {
        return Request::create('/beam-ux-entries/'.$entry->id.'/save-body', 'POST', ['body' => $body]);
    }

    private function attributeOn(string $class): ParticleOp
    {
        return (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();
    }

    /**
     * The declaring class behind a registered operation, read back off the registry rather than
     * assumed — `handle` is a closure over the op class, so the round trip is what proves the
     * registration used the real declaration site.
     */
    private function attributeClassFor(ParticleOperationRegistry $registry, string $name): string
    {
        $operation = $registry->find('beam-ux-entry', $name);

        foreach ([EntryWorkflowShowOp::class, EntryWorkflowTransitionOp::class, EntryBodyShowOp::class, EntryBodySaveOp::class] as $class) {
            if ($this->attributeOn($class)->name === $operation->name) {
                return $class;
            }
        }

        return '';
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            // The Publication Pin: save-body is its own publish, and since ADR-0225 decision 2 a publish
            // requires the version store (the pin-less fallback is gone).
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });

        // EntryPublication audits every restore and clear to spatie's activity_log (ADR-0225 d8), and the restore test
        // restores; the same shape as EditorKitTest's.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
}
