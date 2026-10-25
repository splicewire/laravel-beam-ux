<?php

namespace Splicewire\Beam\Ux\Publish;

use Illuminate\Contracts\Events\Dispatcher;
use Splicewire\Beam\Events\BeamParticlePersisted;

/** Per-writer dispatch adapter: a rolled-back author save emits no post-persist signal.
 * This is passed only to the existing ParticleWriter; no application dispatcher is rebound. */
final class AfterCommitParticleEvents extends \Illuminate\Events\Dispatcher
{
    public function __construct(private Dispatcher $target)
    {
        parent::__construct();
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        if ($event instanceof BeamParticlePersisted) {
            $event->record->getConnection()->afterCommit(fn () => $this->target->dispatch($event, $payload, $halt));

            return null;
        }

        return $this->target->dispatch($event, $payload, $halt);
    }
}
