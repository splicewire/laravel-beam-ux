<?php

namespace Splicewire\Beam\Ux\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Ux\Intake\IntakeForms;

/**
 * EP-07d — the page forms that post to laravel-beam's public intake door. Two host facts the form cannot check itself:
 *
 * - **The honeypot.** The form always sends the door's honeypot field, but the door's middleware only acts where
 *   `beam.core.intake.honeypot.enabled` is on (a host's published config sets it, off in every one so far). Where
 *   forms are offered and it is off, this warns.
 * - **The honeypot's field name.** The door strips that field from every payload, and with the honeypot on a filled one
 *   drops the whole submission. A public schema that declares a real property by that name (the default is
 *   `website`) would lose it, or every submission that fills it; this warns, naming the schema.
 *
 * Both warn and never fail: they are host choices. With the door off no form is offered, and that passes.
 */
class BeamUxIntakeFormAudit implements DoctorAudit
{
    private const CHECK = 'Intake forms';

    public function __construct(private ?SchemaTargetResolver $targets = null) {}

    /** @return list<Finding> */
    public function run(): array
    {
        if (! IntakeForms::doorEnabled()) {
            return [Finding::pass(self::CHECK, 'the public intake door is off (beam.core.intake.enabled), so no page offers a form.')];
        }

        $field = (string) config('beam.core.intake.honeypot.field', 'website');
        $findings = [config('beam.core.intake.honeypot.enabled', false)
            ? Finding::pass(self::CHECK.' (honeypot)', "page forms send the [{$field}] honeypot field and the door refuses one a bot filled.")
            : Finding::warn(self::CHECK.' (honeypot)', "page forms are offered and send the [{$field}] honeypot field, but the door's honeypot is off. "
                .'Turn on beam.core.intake.honeypot.enabled so a bot that fills it is silently refused.')];

        $schemas = (array) config('beam.core.intake.public_schemas', []);
        $colliding = [];
        foreach ($schemas as $stem) {
            $schema = ($this->targets ?? app(SchemaTargetResolver::class))->targetFor((string) $stem);
            if (array_key_exists($field, (array) ($schema['properties'] ?? []))) {
                $colliding[] = (string) $stem;
            }
        }
        $findings[] = $colliding === []
            ? Finding::pass(self::CHECK.' (honeypot field)', count($schemas)." public intake schemas checked; none declares a [{$field}] property.")
            : Finding::warn(self::CHECK.' (honeypot field)', 'these public schemas declare a real ['.$field.'] property, which the door strips from every submission: '
                .implode(', ', $colliding).'. Rename beam.core.intake.honeypot.field, or the property.');

        return $findings;
    }
}
