<?php

namespace Splicewire\Beam\Ux\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\BeamUxEntryFormInputData;
use Splicewire\Beam\Ux\Data\BeamUxEntrySourceInputData;
use Splicewire\Beam\Ux\Particle\EntryFormSaveOp;
use Splicewire\Beam\Ux\Particle\EntrySourceSaveOp;

class EntryBodySaveAffordanceTest extends TestCase
{
    public static function saveOperations(): array
    {
        return [
            'source' => [EntrySourceSaveOp::class, 'save-source', BeamUxEntrySourceInputData::class],
            'form' => [EntryFormSaveOp::class, 'save-form', BeamUxEntryFormInputData::class],
        ];
    }

    #[DataProvider('saveOperations')]
    public function test_editor_saves_explicitly_suppress_frame_action_buttons(string $class, string $name, string $input): void
    {
        $attributes = (new ReflectionClass($class))->getAttributes(ParticleOp::class);
        $this->assertCount(1, $attributes);
        $this->assertArrayHasKey('affordance', $attributes[0]->getArguments(), 'Every new op must declare its affordance explicitly.');
        $operation = $attributes[0]->newInstance();
        $this->assertFalse($operation->affordance);
        $this->assertSame('beam-ux-entry', $operation->resource);
        $this->assertSame($name, $operation->name);
        $this->assertSame(OperationKind::Write, $operation->kind);
        $this->assertSame('ux.author', $operation->ability);
        $this->assertFalse($operation->abilityModel);
        $this->assertSame($input, $operation->input);
        $this->assertSame(BeamUxEntryBodyData::class, $operation->output);
    }
}
