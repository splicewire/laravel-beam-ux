<?php

namespace Splicewire\Beam\Ux\Placement;

use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **file-placement port** (charter S2; ADR-0165) — the beam-ux policy that derives a
 * {@see BeamUxEntry}'s **disk path** from its authoring envelope, replacing the pre-format world's
 * hardcoded `type/namespace/component.tsx`. It is beam-ux's half of the storage seam: the generic
 * {@see StorageDriver} port is beam-core's; only this format-aware
 * *placement policy* is beam-ux's.
 *
 * It honors the **two trees** (ADR-0165): `namespace` is the **disk-grouping coordinate ONLY** — it
 * derives *placement*, NEVER the URL (the URL is S3's containment tree). The default strategy composes
 * `namespace` (dots→slashes) + `type` + `slug` + the **format extension** (sourced from the entry's
 * `format` via its codec, ADR-0164 — no longer a hardcoded `.tsx`); the {@see DatePartitionedPlacement}
 * strategy files content by date. A {@see PlacementResolver} picks the strategy per entry
 * (per-entry ref → per-namespace map → default).
 */
interface FilePlacement
{
    /** The disk-relative path this entry materializes to (the `key` a StorageDriver writes under). */
    public function pathFor(BeamUxEntry $entry): string;
}
