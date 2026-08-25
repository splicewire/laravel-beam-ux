<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Type\WorkflowTypeRegistry;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresEntryWorkflow
{
    /**
     * The **workflow** seam (charter S6): beam-ux ships the default entry publish lifecycle
     * ({@see EntryPublishLifecycle}) so a host can bind a type into it, and enumerates the entry
     * type keys for the workflows admin. It registers NO binding — the optional-workflow rule: an
     * entry is unmanaged (no state machine) until a host binds its `type` on the
     * {@see WorkflowBindingRegistry}. Guarded on the sibling
     * `laravel-beam-workflows` engine being installed, so beam-ux still boots without it (the
     * publish gate then simply reports every entry unmanaged ⇒ published).
     */
    #[Chained('boot', order: 20)]
    protected function registerEntryWorkflow(): void
    {
        if (! class_exists(WorkflowRegistry::class) || ! $this->app->bound(WorkflowRegistry::class)) {
            return;
        }

        $this->app->make(WorkflowRegistry::class)
            ->register(EntryPublishLifecycle::DEFINITION, EntryPublishLifecycle::blueprint());

        if (class_exists(WorkflowTypeRegistry::class) && $this->app->bound(WorkflowTypeRegistry::class)) {
            $this->app->make(WorkflowTypeRegistry::class)
                ->register(UxType::Page->value, 'Page')
                ->register(UxType::Component->value, 'Component');
        }
    }
}
