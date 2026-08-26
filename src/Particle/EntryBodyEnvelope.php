<?php

namespace Splicewire\Beam\Ux\Particle;

use Splicewire\Beam\Ux\Canvas\ViewGateFilter;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Builds the {@see BeamUxEntryBodyData} envelope both entry-body operations return — the projection
 * half of ADR-0214, factored out of the retired `BeamUxEntryBodyController` so the read op and the
 * write op's re-read cannot drift.
 *
 * They drifting is not hypothetical: the whole reason ADR-0214 exists is that `splicewire-app` shipped
 * a second copy of this projection and it diverged six ways. Two operations in one package would have
 * been the third copy.
 *
 * A service and not a static helper because it depends on the resolved {@see StorageDriverResolver}
 * and {@see ViewGateFilter}, both of which a host may rebind — the ops resolve it from the container
 * the way {@see \Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp} resolves its actuator.
 */
class EntryBodyEnvelope
{
    public function __construct(
        private StorageDriverResolver $drivers,
        private ViewGateFilter $viewGateFilter,
    ) {}

    /**
     * The entry's CURRENT body, view-gate filtered — the read op's whole answer, and the shape the
     * write op re-reads into after a save.
     *
     * Per-node `data-view-gate` entitlement keys are enforced HERE, because this is the one place a
     * body reaches a client and therefore the one place that can strip what an unentitled viewer must
     * never receive.
     */
    public function read(BeamUxEntry $entry): BeamUxEntryBodyData
    {
        $item = $entry->particle_id !== null
            ? $this->drivers->resolve($entry)->read((string) $entry->particle_id)
            : null;

        return $this->of($entry, $this->viewGateFilter->filter($item?->body ?? []));
    }

    /**
     * The envelope around an ALREADY-RESOLVED body — used by the write path, which has just persisted
     * the caller's own document and re-read it, and by {@see read()} above.
     *
     * @param  array<string, mixed>  $body
     */
    public function of(BeamUxEntry $entry, array $body, ?string $compileError = null): BeamUxEntryBodyData
    {
        return new BeamUxEntryBodyData(
            slug: (string) $entry->slug,
            id: (string) $entry->id,
            type: $this->typeValue($entry),
            schema: $this->schemaFor($entry),
            body: $body,
            compileError: $compileError,
        );
    }

    /**
     * The JSON-Schema the SchemaForm renders. `schema_ref` carries EITHER an inline JSON schema (an
     * inferred draft) OR a bare registry stem; we surface the inline object when present. A `theme`
     * entry carries no `schema_ref` (its body is a `{canvas,site}` token object, not a component's
     * inferred props) — it falls back to {@see ThemeSchemas}, INLINED by value rather than
     * `$ref`-composed (the two sub-schemas are already flat; a caller's `SchemaForm` renders this with
     * no `schemaFetcher` round trip). `shell` is omitted — no host consuming this today has an `/os`
     * windowed-desktop chrome to theme.
     *
     * @return array<string, mixed>|null
     */
    public function schemaFor(BeamUxEntry $entry): ?array
    {
        $ref = $entry->schema_ref;

        if (is_string($ref) && $ref !== '') {
            $decoded = json_decode($ref, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($entry->type === UxType::Theme) {
            return [
                'type' => 'object',
                'title' => 'Theme',
                'properties' => [
                    'canvas' => ThemeSchemas::canvas(),
                    'site' => ThemeSchemas::site(),
                ],
            ];
        }

        return null;
    }

    /** The `UxType` as its wire string, whether the model casts the column or leaves it raw. */
    public function typeValue(BeamUxEntry $entry): string
    {
        $type = $entry->getAttribute('type');

        return is_object($type) && property_exists($type, 'value')
            ? (string) $type->value
            : (string) $type;
    }
}
