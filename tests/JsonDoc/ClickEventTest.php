<?php

namespace Splicewire\Beam\Ux\Tests\JsonDoc;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Codec\JsonDocPrinter;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\JsonDoc\JsonBlockNode;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\AuthoredComponentNativeTestCase;

/**
 * EP-29 slice 2 (ADR-0234 decisions 13–14; ADR-0237 decision 8; contract: launch EP29-SLICE-PLAN.md). A block may name a
 * Click Event from the Event Vocabulary; the Manifest Profile serves that vocabulary as `x-events`; a save and a Publish
 * refuse a name it does not serve; the source printer writes `bdEvent` and the compiled artifact `data-bd-event`.
 */
class ClickEventTest extends AuthoredComponentNativeTestCase
{
    private function entry(): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => 'click-events', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
    }

    private function block(array $clickEvent): array
    {
        return ['kind' => 'block', 'name' => 'p', 'id' => 'cta', 'isComponent' => false, 'dynamic' => false,
            'props' => [], 'children' => [['kind' => 'text', 'value' => 'Start']], 'clickEvent' => $clickEvent];
    }

    public function test_the_profile_serves_the_package_and_host_event_vocabulary(): void
    {
        config()->set('beam.analytics.events', ['play_demo' => ['song_id']]);

        $events = app(ManifestProfileResolver::class)->resolve($this->entry())['x-events'];

        $this->assertSame(['name' => 'sign_up', 'source' => 'package', 'parameters' => ['method']], $events[0]);
        $this->assertContains(['name' => 'play_demo', 'source' => 'host', 'parameters' => ['song_id']], $events);
    }

    public function test_a_save_and_a_publish_refuse_a_click_event_the_profile_does_not_serve(): void
    {
        $entry = $this->entry();
        $body = [$this->block(['name' => 'page_view_custom'])];
        $guard = app(CanvasBodyGuard::class);

        foreach (['save' => fn () => $guard->assertAdmitted($entry, $body), 'publish' => fn () => $guard->assertPublishable($entry, $body, [])] as $operation => $check) {
            try {
                $check();
                $this->fail("Accepted an undeclared Click Event on {$operation}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('clickEvent.name', $e->errors(), $operation);
            }
        }
    }

    public function test_a_declared_click_event_passes_the_vocabulary_check(): void
    {
        $profile = app(ManifestProfileResolver::class)->resolve($this->entry());

        app(\Splicewire\Beam\Ux\Analytics\ClickEventGuard::class)->assert([$this->block(['name' => 'generate_lead', 'label' => 'Hero CTA'])], $profile);

        $this->addToAssertionCount(1);
    }

    public function test_the_source_printer_writes_bd_event_and_omits_an_absent_label(): void
    {
        $printed = JsonDocPrinter::print([$this->block(['name' => 'generate_lead', 'label' => 'Hero CTA'])]);
        $this->assertStringContainsString('bdEvent={{"name":"generate_lead","label":"Hero CTA"}}', $printed);

        $unlabelled = JsonDocPrinter::print([$this->block(['name' => 'sign_up'])]);
        $this->assertStringContainsString('bdEvent={{"name":"sign_up"}}', $unlabelled);
    }

    public function test_the_compiled_artifact_carries_a_data_attribute_not_a_jsx_prop(): void
    {
        $module = JsonDocPrinter::printModule([$this->block(['name' => 'generate_lead', 'label' => 'Hero CTA'])]);

        $this->assertStringContainsString('data-bd-event={'.json_encode(json_encode(['name' => 'generate_lead', 'label' => 'Hero CTA'])).'}', $module);
        $this->assertStringNotContainsString('bdEvent=', $module);
    }

    public function test_a_block_hydrates_its_click_event_and_round_trips_it(): void
    {
        $node = JsonNode::from($this->block(['name' => 'generate_lead', 'label' => 'Hero CTA']));

        $this->assertInstanceOf(JsonBlockNode::class, $node);
        $this->assertSame('generate_lead', $node->clickEvent?->name);
        $this->assertSame(['name' => 'generate_lead', 'label' => 'Hero CTA'], $node->toArray()['clickEvent']);
        $this->assertSame(['name' => 'sign_up'], JsonNode::from($this->block(['name' => 'sign_up']))->toArray()['clickEvent']);
    }
}
