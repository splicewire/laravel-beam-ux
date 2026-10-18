<?php

namespace Splicewire\Beam\Ux\Release;

use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;

final class ReleaseLifecycle
{
    public const DEFINITION = 'beam-ux.release';

    public static function blueprint(): WorkflowBlueprint
    {
        return WorkflowBlueprint::fromArray([
            'name' => self::DEFINITION,
            'places' => ['draft', 'in_review', 'approved', 'scheduled', 'published'],
            'initial' => ['draft'],
            'transitions' => [
                ['name' => 'submit', 'from' => 'draft', 'to' => 'in_review'],
                ['name' => 'approve', 'from' => 'in_review', 'to' => 'approved'],
                ...array_map(fn ($from) => ['name' => 'revise', 'from' => $from, 'to' => 'draft'], ['draft', 'in_review', 'approved', 'scheduled']),
                ['name' => 'schedule', 'from' => 'approved', 'to' => 'scheduled'],
                ...array_map(fn ($from) => ['name' => 'publish', 'from' => $from, 'to' => 'published'], ['draft', 'approved', 'scheduled']),
            ],
        ]);
    }
}
