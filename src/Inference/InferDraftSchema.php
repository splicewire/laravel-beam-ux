<?php

namespace Splicewire\Beam\Ux\Inference;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **draft-schema authoring action** (beam-ux, charter S9). The clean service seam S8's
 * `register-from-disk` batch invokes at import: given an entry and its component `.tsx` source, it runs
 * {@see TsxPropInference}, stores the inferred JSON-Schema as the entry's `schema_ref`, and marks the
 * record **DRAFT** (`schema_is_draft = true`).
 *
 * **DRAFT, never final (the load-bearing rule, ADR-0138 beam-core).** The draft flag is what makes a
 * fresh component editable immediately WITHOUT pretending the inferred schema is authoritative. Widgets,
 * validation, and `$ref`s remain explicit authoring acts — graduation (clearing the draft flag) is a
 * separate deliberate step, never a side effect of inference. This action ONLY ever SETS the draft flag;
 * it never clears it.
 *
 * **Exclusion is enforced upstream.** For `page`/`layout`/`template` this action is a no-op that returns
 * the entry untouched — their schemas come from the composition model, not props (asserted by
 * {@see TsxPropInference::infersFor()}). Only `component` (subsuming the inline "node") is inferred.
 *
 * The inferred schema BODY is what {@see BeamUxEntry::$schema_ref} carries — beam-ux stores the draft
 * schema on the entry's flat authoring envelope; the versioned particle BODY (written through beam-core's
 * shared `ParticleWriter`) is untouched. Inference is beam-ux's; the particle it rides is
 * beam-core's (ADR-0092 vendor seam).
 */
class InferDraftSchema
{
    public function __construct(protected TsxPropInference $inference) {}

    /**
     * Infer + store the draft schema on the entry from its component source. Returns the same entry
     * (unsaved by default — the caller decides when to persist; pass $persist to save in place).
     *
     * A `page`/`layout`/`template` entry is returned untouched (inference is excluded for it).
     */
    public function forEntry(BeamUxEntry $entry, string $source, bool $persist = false): BeamUxEntry
    {
        $type = $entry->type;

        if ($type === null || ! $this->inference->infersFor($type)) {
            return $entry;
        }

        $schema = $this->inference->infer($source);

        $entry->schema_ref = $this->encode($schema);
        $entry->schema_is_draft = true;

        if ($persist) {
            $entry->save();
        }

        return $entry;
    }

    /**
     * Infer the draft schema BODY for a component source WITHOUT touching a record — the pure form S8 or
     * a preview surface can call to see what would be inferred. Asserts the type is inferable.
     *
     * @return array<string, mixed>
     */
    public function infer(string $source): array
    {
        return $this->inference->infer($source);
    }

    /**
     * The stored form of the inferred schema on `schema_ref`. The envelope column is a string; the draft
     * schema is a JSON object, so it is stored as canonical (unescaped-slash, stable-key) JSON. The
     * `schema_is_draft` column — NOT anything embedded in this string — is the authoritative draft
     * marker, so a graduated schema keeps the same `schema_ref` string and only flips the flag.
     */
    protected function encode(array $schema): string
    {
        return json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
