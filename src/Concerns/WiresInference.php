<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Inference\InferDraftSchema;
use Splicewire\Beam\Ux\Inference\TsxPropInference;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresInference
{
    /**
     * The prop→draft-schema inference seam (charter S9, `beamux-build/issues/06`). Binds the
     * deterministic {@see TsxPropInference} parser + the {@see InferDraftSchema} authoring action as
     * singletons — the clean service port S8's `register-from-disk` batch resolves to infer a DRAFT
     * schema for a freshly-registered `component` at import. Composition seam (ADR-0092): the
     * inference engine + the draft schema-ref it writes are beam-ux's; the particle body is
     * beam-core's.
     */
    #[Chained('register', order: 40)]
    protected function registerInference(): void
    {
        $this->app->singleton(TsxPropInference::class);
        $this->app->singleton(InferDraftSchema::class);
    }
}
