<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Illuminate\Validation\ValidationException;
use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;
use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;

#[NodeType('SlotGuardCard')]
class SlotGuardCard extends Block
{
    public function category(): ?string
    {
        return 'html';
    }

    public function slots(): array
    {
        return [['name' => 'content', 'admitsChildCategories' => ['html']], ['name' => 'sealed', 'admitsChildCategories' => []]];
    }
}

class SlotBodyGuardTest extends TestCase
{
    public function test_save_and_publish_enforce_the_resolved_slot_profile(): void
    {
        $tenancy = \Mockery::mock(TenancyContext::class);
        $tenancy->shouldReceive('centralConnection')->andReturn('central');
        $this->app->instance(TenancyContext::class, $tenancy);
        $this->app->instance(ManifestNodeSource::class, new class implements ManifestNodeSource
        {
            public function profiles(): array
            {
                return ['content'];
            }

            public function nodeClassesFor(string $profile): array
            {
                return [SlotGuardCard::class];
            }

            public function docAdmitsFor(string $profile): ?array
            {
                return ['html'];
            }

            public function marksFor(string $profile): array
            {
                return [];
            }
        });
        $entry = new BeamUxEntry;
        $guard = $this->app->make(CanvasBodyGuard::class);
        $child = ['kind' => 'block', 'id' => 'child', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []];
        $body = [['kind' => 'block', 'id' => 'card', 'name' => 'SlotGuardCard', 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => [], 'slots' => ['content' => [$child]]]];
        foreach (['save', 'publish'] as $operation) {
            $check = fn (array $value) => $operation === 'save'
                ? $guard->assertAdmitted($entry, $value)
                : $guard->assertPublishable($entry, $value, []);
            $check($body);
            $this->addToAssertionCount(1);
            foreach (['sealed', 'undeclared', 'code'] as $invalid) {
                $bad = $body;
                $bad[0]['slots'] = $invalid === 'code'
                    ? ['content' => [['kind' => 'opaque', 'reason' => 'expression', 'source' => '{steal()}']]]
                    : [$invalid => [$child]];
                try {
                    $check($bad);
                    $this->fail($operation.' accepted '.$invalid.' slot content');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('body', $e->errors());
                }
            }
        }
    }
}
