<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Exercises the dependency algorithm with explicit versioned documents, without filesystem or database writes. */
class AuthoredComponentGraphTest extends TestCase
{
    private function node(string $name, array $children = []): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'dynamic' => false, 'props' => [], 'children' => $children];
    }

    private function body(string $name, array $uses = []): array
    {
        return [$this->node('div', array_map(fn ($name) => $this->node($name), $uses)) + ['component' => ['name' => $name, 'props' => []]]];
    }

    private function graph(array $documents, array $pins = []): AuthoredComponents
    {
        return new class($documents, $pins) extends AuthoredComponents
        {
            public array $catalog = [];

            public function __construct(private array $documents, private array $pins)
            {
                foreach ($documents as $name => $body) {
                    $this->catalog[$name] = (new BeamUxEntry)->forceFill(['id' => $name, 'type' => 'component', 'format' => 'tsx', 'component_kind' => 'authored', 'published_version' => isset($pins[$name]) ? 'pin' : null]);
                }
            }

            public function entries(BeamUxEntry $context): array
            {
                return array_values($this->catalog);
            }

            public function working(BeamUxEntry $entry): array
            {
                return $this->documents[$entry->getKey()] ?? [];
            }

            public function version(BeamUxEntry $entry, ?string $ref): array
            {
                return $ref === 'release' ? $this->working($entry) : ($this->pins[$entry->getKey()] ?? []);
            }

            public function canRead(BeamUxEntry $entry, ?\Illuminate\Contracts\Auth\Authenticatable $actor): bool
            {
                return true;
            }
        };
    }

    private function profile(): array
    {
        return ['doc' => ['admitsChildCategories' => ['html']], 'nodes' => [['name' => 'div', 'category' => 'html', 'admitsChildCategories' => ['html']]]];
    }

    public function test_save_and_publish_refuse_cycles_but_publish_uses_pins_not_pending_heads(): void
    {
        $a = $this->body('A', ['B']);
        $b = $this->body('B', ['A']);
        $graph = $this->graph(['A' => $a, 'B' => $b], ['A' => $this->body('A'), 'B' => $this->body('B')]);
        $entry = $graph->entries(new BeamUxEntry)[0];
        try {
            $graph->assertGraph($entry, $a, profile: $this->profile());
            $this->fail('Save accepted a Working Head cycle.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('cycle', $error->getMessage());
        }
        $graph->assertGraph($entry, $a, publishing: true, profile: $this->profile());
        $this->addToAssertionCount(1);
        $this->expectException(ValidationException::class);
        $graph->assertGraph($entry, $a, publishing: true, refs: ['B' => 'release'], profile: $this->profile());
    }

    public function test_never_published_component_requires_its_same_release_version(): void
    {
        $graph = $this->graph(['Card' => $this->body('Card')]);
        $page = (new BeamUxEntry)->forceFill(['id' => 'page', 'type' => 'page', 'format' => 'tsx']);
        $body = [$this->node('Card')];
        try {
            $graph->assertGraph($page, $body, publishing: true, profile: $this->profile());
            $this->fail('A never-published component went live.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('same Release', $error->getMessage());
        }
        $graph->assertGraph($page, $body, publishing: true, refs: ['Card' => 'release'], profile: $this->profile());
        $this->addToAssertionCount(1);
    }

    public function test_deleted_dependency_refuses_even_when_an_unchanged_page_is_republished(): void
    {
        $graph = $this->graph([]);
        $this->expectException(ValidationException::class);
        $graph->assertGraph((new BeamUxEntry)->forceFill(['id' => 'page']), [$this->node('MissingCard')], publishing: true, profile: $this->profile());
    }

    public function test_blocks_layout_requires_one_unnamed_slot(): void
    {
        $entry = new BeamUxEntry(['type' => 'layout', 'format' => 'tsx']);
        $service = new AuthoredComponents;
        [$profile, $name] = $service->forBody($entry, [$this->node('div', [$this->node('Slot')])], $this->profile());
        $chrome = array_column($profile['nodes'], null, 'name')[$name];
        $schema = json_decode(json_encode($chrome['attrsSchema'], JSON_THROW_ON_ERROR));
        $this->assertTrue((new \Opis\JsonSchema\Validator)->validate(new \stdClass, $schema)->isValid());
        $this->expectException(ValidationException::class);
        $service->forBody($entry, [$this->node('div')], $this->profile());
    }
}
