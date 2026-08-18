<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\MirrorGitStatus;

/**
 * The `beam.ux.mirror-status.show` endpoint — one row per `BeamUxEntry`, its resolved
 * {@see \Splicewire\Beam\Ux\Placement\FilePlacement} path (the SAME `PlacementResolver`/`codec()`
 * chain {@see BeamUxEntryBodyController::update()} already uses to write the real mirror file, so
 * this can never report a DIFFERENT path than the one the mirror actually targets), and its
 * {@see MirrorGitStatus} verdict.
 *
 * Degrades to `{enabled: false, entries: []}` with ZERO per-row work when no mirror disk is
 * configured — {@see MirrorGitStatus::enabled()} is checked once, up front, not per row.
 */
class BeamUxMirrorStatusController
{
    public function __construct(
        private PlacementResolver $placements,
        private MirrorGitStatus $gitStatus,
    ) {}

    public function show(): JsonResponse
    {
        if (! $this->gitStatus->enabled()) {
            return response()->json(['data' => ['enabled' => false, 'entries' => []]]);
        }

        $rows = BeamUxEntry::query()
            ->orderBy('namespace')
            ->orderBy('slug')
            ->get()
            ->map(fn (BeamUxEntry $entry) => $this->rowFor($entry))
            ->all();

        return response()->json(['data' => ['enabled' => true, 'entries' => $rows]]);
    }

    /** @return array<string, mixed> */
    private function rowFor(BeamUxEntry $entry): array
    {
        $base = [
            'id' => (string) $entry->id,
            'namespace' => $entry->namespace,
            'slug' => $entry->slug,
            'type' => $entry->type?->value,
        ];

        if ($entry->particle_id === null) {
            return [...$base, 'path' => null, 'exists' => false, 'lastModifiedAt' => null, 'state' => 'not-yet-saved'];
        }

        $path = $this->placements->resolve($entry)->pathFor($entry);

        return [...$base, ...$this->gitStatus->statusFor($path)];
    }
}
