<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Ux\Doctor\BeamUxIntakeFormAudit;
use Splicewire\Beam\Ux\Tests\TestCase;

/** EP-07d: the honeypot page forms rely on, and the field name it strips. */
class BeamUxIntakeFormAuditTest extends TestCase
{
    private function audit(): BeamUxIntakeFormAudit
    {
        return new BeamUxIntakeFormAudit(new class implements SchemaTargetResolver
        {
            public function targetFor(string $recordType, ?int $version = null): array
            {
                return match ($recordType) {
                    'contact' => ['properties' => ['email' => ['type' => 'string']]],
                    'partner' => ['properties' => ['website' => ['type' => 'string']]],
                    default => [],
                };
            }
        });
    }

    public function test_with_the_door_off_no_form_is_offered_and_it_passes(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_it_warns_while_forms_are_offered_with_the_honeypot_off_and_when_a_schema_declares_its_field(): void
    {
        config(['beam.core.intake.enabled' => true, 'beam.core.intake.public_schemas' => ['contact']]);
        [$honeypot, $field] = $this->audit()->run();
        $this->assertSame(DoctorStatus::Warn, $honeypot->status);
        $this->assertStringContainsString('beam.core.intake.honeypot.enabled', $honeypot->detail);
        $this->assertSame(DoctorStatus::Pass, $field->status);

        config(['beam.core.intake.honeypot.enabled' => true, 'beam.core.intake.public_schemas' => ['contact', 'partner']]);
        [$honeypot, $field] = $this->audit()->run();
        $this->assertSame(DoctorStatus::Pass, $honeypot->status);
        $this->assertSame(DoctorStatus::Warn, $field->status);
        $this->assertStringContainsString('partner', $field->detail);
        $this->assertStringNotContainsString('contact', $field->detail);
    }
}
