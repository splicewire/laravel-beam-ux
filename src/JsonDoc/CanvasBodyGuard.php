<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\Manifest\BodyNodeDiff;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * ADR-0228 d8 / ux-0227 d1 — the shared save-time guard for a canvas (JsonDoc) body, used by every path
 * that stores one: {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} (the typed JsonDoc write) and
 * {@see \Splicewire\Beam\Ux\Particle\EntrySourceSaveOp} (a tsx source write lowered through the d9 Lens),
 * so a lowered body is held to exactly the same profile as a directly-authored one.
 *
 * It resolves the realm's Manifest Profile, narrows the LEGALITY checks (unknown-name, containment,
 * attrsSchema) to only the nodes this save adds or changes versus the Working Head ({@see BodyNodeDiff}),
 * and leaves the ux-0227 d1 code refusal (opaque node + expression prop) whole-body.
 */
final class CanvasBodyGuard
{
    public function __construct(
        private ManifestProfileResolver $resolver,
        private ProfileLegality $legality,
        private StorageDriverResolver $drivers,
        private BodyNodeDiff $diff = new BodyNodeDiff,
    ) {}

    /**
     * The entry's current stored body (the Working Head), read before the write. Empty for a first save
     * or a non-JsonDoc body.
     *
     * @return array<int|string, mixed>
     */
    public function workingHead(BeamUxEntry $entry): array
    {
        $key = $entry->particle_id;
        if (! is_string($key) || $key === '') {
            return [];
        }

        $body = $this->drivers->resolve($entry)->read($key)?->body ?? [];

        return is_array($body) ? $body : [];
    }

    /**
     * Refuse a canvas body the realm's resolved Manifest Profile does not admit, as a 422 on `body`
     * naming each offending node by id + path. A no-op for a non-JsonDoc body.
     *
     * @param  array<int|string, mixed>  $body
     */
    public function assertAdmitted(BeamUxEntry $entry, array $body): void
    {
        if (! JsonDocShape::is($body)) {
            return;
        }

        // ADR-0228 d2 — well-formedness first: every node must hydrate through the JsonNode union, or the
        // document is one the canvas editor cannot read. A bad node (unknown kind, malformed field)
        // becomes the same clean 422 as any other bad input, never a 500 from deep in the hydrator.
        try {
            foreach ($body as $node) {
                JsonNode::from($node);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'body' => 'This document has a node the canvas editor cannot read ('.$e->getMessage().').',
            ]);
        }

        $profile = $this->resolver->resolve($entry);
        $checkIds = $this->diff->changedIds($this->workingHead($entry), $body);

        $this->legality->assert($body, $profile, $checkIds);
    }
}
