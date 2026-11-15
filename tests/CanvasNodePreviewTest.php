<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\NodeActions\CanvasNodePreview;
use Splicewire\Composition\NodeActions\NodeActionCandidateData;
use Splicewire\Composition\NodeActions\NodeActionCandidateInputData;
use Splicewire\Composition\NodeActions\NodeActionCandidateProducer;
use Splicewire\Composition\NodeActions\NodeActionMode;

class CanvasNodePreviewTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn ($actor, $ability) => $ability === 'consume.engine' ? ($actor->consume ?? false) : ($actor->author ?? false));
    }

    private function node(string $text = 'Before', string $name = 'p'): array
    {
        return ['kind' => 'block', 'id' => '019a8000-0000-7000-8000-000000000001', 'name' => $name,
            'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => $text]]];
    }

    private function entry(): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => 'preview', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
    }

    private function producer(array $candidate): NodeActionCandidateProducer
    {
        return new class($candidate) implements NodeActionCandidateProducer
        {
            public int $calls = 0;

            public function __construct(private array $candidate) {}

            public function produce(NodeActionCandidateInputData $request): NodeActionCandidateData
            {
                $this->calls++;

                return new NodeActionCandidateData($this->candidate);
            }
        };
    }

    public function test_offers_a_guarded_rewrite_preserving_identity_without_any_write(): void
    {
        $entry = $this->entry();
        $before = $entry->fresh()->getAttributes();
        $candidate = $this->node('After');
        unset($candidate['id']);
        $producer = $this->producer($candidate);
        $service = new CanvasNodePreview($producer);
        $db = $entry->getConnection();
        $db->enableQueryLog();
        $db->flushQueryLog();
        $decision = $service->preview($entry, new GenericUser(['id' => 'author', 'author' => true]),
            [$this->node()], [0], null, NodeActionMode::Refine, 'Shorten it');
        $queries = $db->getQueryLog();
        $db->disableQueryLog();
        $this->assertSame('kept', $decision->decision);
        $this->assertSame($this->node()['id'], $decision->revision->after->truth['id']);
        $this->assertSame('After', $decision->revision->after->truth['children'][0]['value']);
        $this->assertSame([], $decision->revision->after->standing);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }

    public function test_illegal_block_is_refused_with_the_original_truth(): void
    {
        $decision = (new CanvasNodePreview($this->producer($this->node('Bad', 'UnregisteredBlock'))))
            ->preview($this->entry(), new GenericUser(['id' => 'author', 'author' => true]),
                [$this->node()], [0], null, NodeActionMode::Regenerate, null);
        $this->assertSame('reverted', $decision->decision);
        $this->assertTrue($decision->verdict->hardFail);
        $this->assertSame($decision->revision->before->truth, $decision->revision->after->truth);
    }

    public function test_non_author_is_denied_before_the_producer_runs(): void
    {
        $producer = $this->producer($this->node('After'));
        try {
            (new CanvasNodePreview($producer))->preview($this->entry(), null, [$this->node()], [0], null, NodeActionMode::Refine, 'Shorten');
            $this->fail('Guest preview succeeded');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, $producer->calls);
    }

    public function test_tenant_entry_cannot_receive_generated_code(): void
    {
        $tenancy = \Mockery::mock(\Splicewire\Beam\Realm\Contracts\TenancyContext::class);
        $tenancy->shouldReceive('centralConnection')->andReturn('central');
        $tenancy->shouldReceive('isActive')->andReturn(true);
        $this->app->instance(\Splicewire\Beam\Realm\Contracts\TenancyContext::class, $tenancy);
        $candidate = $this->node('After');
        $candidate['props'] = [['name' => 'hidden', 'kind' => 'expression', 'value' => 'steal()']];
        $decision = (new CanvasNodePreview($this->producer($candidate)))->preview($this->entry(),
            new GenericUser(['id' => 'author', 'author' => true]), [$this->node()], [0], null, NodeActionMode::Regenerate, null);
        $this->assertSame('reverted', $decision->decision);
        $this->assertTrue($decision->verdict->hardFail);
        $this->assertSame($decision->revision->before->truth, $decision->revision->after->truth);
    }

    public function test_checks_the_dirty_parent_context_not_just_the_selected_node(): void
    {
        $body = [$this->node('', 'img')];
        $body[0]['children'] = [$this->node('Selected')];
        $body[0]['children'][0]['id'] = '019a8000-0000-7000-8000-000000000002';
        $decision = (new CanvasNodePreview($this->producer($this->node('After', 'span'))))->preview($this->entry(),
            new GenericUser(['id' => 'author', 'author' => true]), $body, [0, 'children', 0], null, NodeActionMode::Regenerate, null);
        $this->assertSame('reverted', $decision->decision);
        $this->assertTrue($decision->verdict->hardFail);
    }

    public function test_existing_id_survives_a_stale_path(): void
    {
        $body = [$this->node('Unchanged', 'div')];
        $body[0]['slots'] = ['aside' => [$this->node('Selected')]];
        $body[0]['slots']['aside'][0]['id'] = '019a8000-0000-7000-8000-000000000002';
        // Use a normal child for the successful guard (an intrinsic div has no named slot contract).
        $body[0]['children'] = $body[0]['slots']['aside'];
        unset($body[0]['slots']);
        $id = $body[0]['children'][0]['id'];
        $decision = (new CanvasNodePreview($this->producer($this->node('After'))))->preview($this->entry(),
            new GenericUser(['id' => 'author', 'author' => true]), $body, [99], $id, NodeActionMode::Refine, 'Shorten');
        $this->assertSame('kept', $decision->decision);
        $this->assertSame($id, $decision->revision->after->truth['id']);
    }

    public function test_typed_http_preview_preserves_literal_strings_and_is_read_only(): void
    {
        $this->app->instance(NodeActionCandidateProducer::class, $this->producer($this->node('  After  ')));
        \Splicewire\Beam\Facades\Particle::ops('preview-entries', 'beam-ux-entry', [\Splicewire\Beam\Ux\Particle\EntryRefineNodeOp::class]);
        $entry = $this->entry();
        $this->actingAs(new GenericUser(['id' => 'author', 'author' => true]));
        $this->postJson('/preview-entries/'.$entry->id.'/refine-node', [
            'body' => [$this->node('  Before  ')], 'path' => [0], 'mode' => 'refine', 'instruction' => 'Shorten',
        ])->assertOk()->assertJsonPath('data.decision', 'kept')
            ->assertJsonPath('data.revision.before.truth.children.0.value', '  Before  ')
            ->assertJsonPath('data.revision.after.truth.children.0.value', '  After  ');
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::count());
        $this->assertSame(0, \Rushing\Versioning\Models\Version::count());
    }

    public function test_host_consumption_gate_refuses_before_any_model_call_and_allows_an_entitled_author(): void
    {
        $producer = $this->producer($this->node('After'));
        $this->app->instance(NodeActionCandidateProducer::class, $producer);
        \Splicewire\Beam\Ux\Support\EditorRoutes::nodeActions('paid-preview', ['can:consume.engine']);
        $entry = $this->entry();
        $wire = ['body' => [$this->node()], 'path' => [0], 'mode' => 'refine', 'instruction' => 'Shorten'];
        $this->actingAs(new GenericUser(['id' => 'author', 'author' => true, 'consume' => false]));
        $this->postJson('/paid-preview/'.$entry->id.'/refine-node', $wire)->assertForbidden();
        $this->assertSame(0, $producer->calls);
        $this->actingAs(new GenericUser(['id' => 'author', 'author' => true, 'consume' => true]));
        $this->postJson('/paid-preview/'.$entry->id.'/refine-node', $wire)->assertOk()->assertJsonPath('data.decision', 'kept');
        $this->assertSame(1, $producer->calls);
    }

    public function test_replacing_the_root_kind_cannot_reuse_its_id_on_a_new_descendant(): void
    {
        $old = $this->node('Before', 'div');
        $candidate = $this->node('', 'section');
        unset($candidate['id']);
        $candidate['children'] = [$old];
        $decision = (new CanvasNodePreview($this->producer($candidate)))->preview($this->entry(),
            new GenericUser(['id' => 'author', 'author' => true]), [$old], [0], null, NodeActionMode::Regenerate, null);
        $this->assertSame('kept', $decision->decision);
        $this->assertSame($old['id'], $decision->revision->after->truth['id']);
        $this->assertNotSame($old['id'], $decision->revision->after->truth['children'][0]['id']);
    }

    public function test_reserves_root_identity_while_preserving_a_surviving_child_id(): void
    {
        $old = $this->node('', 'div');
        $child = $this->node('Same child', 'span');
        $child['id'] = '019a8000-0000-7000-8000-000000000002';
        $old['children'] = [$child];
        $candidate = $old;
        $candidate['name'] = 'section';
        unset($candidate['id']);
        $decision = (new CanvasNodePreview($this->producer($candidate)))->preview($this->entry(),
            new GenericUser(['id' => 'author', 'author' => true]), [$old], [0], null, NodeActionMode::Regenerate, null);
        $this->assertSame('kept', $decision->decision);
        $this->assertSame($old['id'], $decision->revision->before->address);
        $this->assertSame($old['id'], $decision->revision->after->truth['id']);
        $this->assertSame($child['id'], $decision->revision->after->truth['children'][0]['id']);
    }
}
