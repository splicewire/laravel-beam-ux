<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Ux\Sitemap\SiloVisibilityEntitlementGate;

/**
 * A lightweight stand-in for a host's bound `BeamSilo` in the beam-ux test harness — carrying only the
 * `visibility` tier the {@see SiloVisibilityEntitlementGate} reads, without
 * pulling adjacency-list / Scout / permission-cascade into the harness. Named `Silo` (not `FixtureSilo`)
 * so the `siloable` morph derives the real `silo_id` pivot key, exactly as the concrete `BeamSilo` does.
 * A `null`/`public` visibility is public; any other tier is restricted (the flat-model shortcut for the
 * cascade's `effectiveVisibility()`, which the gate falls back to when the model isn't cascade-aware).
 */
class Silo extends Model
{
    use HasUuids;

    protected $table = 'silos';

    protected $guarded = [];
}
