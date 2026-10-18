<?php

namespace Splicewire\Beam\Ux\Bindings;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\Collections\CollectionBacking;

/** Bounded bridge for legacy request-resolved backings; actor and tenant remain the visitor's. */
final class BindingRequestScope
{
    public function run(Request $visitor, array $query, Closure $read): mixed
    {
        $container = app();
        $original = $container->make(Request::class);
        $originalUserResolver = $original->getUserResolver();
        $restoreBacking = $container->isShared(CollectionBacking::class) && $container->resolved(CollectionBacking::class);
        $previousBacking = $restoreBacking ? $container->make(CollectionBacking::class) : null;
        $scoped = $visitor->duplicate($query, []);
        $actor = $visitor->user();
        $scoped->setUserResolver(fn () => $actor);
        try {
            $container->instance('request', $scoped);
            // Laravel's request-rebound listeners install an auth resolver. Keep the explicitly
            // captured visitor after those listeners, including an explicit null guest.
            $scoped->setUserResolver(fn () => $actor);
            Facade::clearResolvedInstance('request');
            // Parameterized make bypasses cached instances; each Repeat owns its saved address.
            $container->instance(CollectionBacking::class, $container->makeWith(CollectionBacking::class, ['request' => $scoped]));

            return $read($scoped);
        } finally {
            $container->instance('request', $original);
            $original->setUserResolver($originalUserResolver);
            Facade::clearResolvedInstance('request');
            $container->forgetInstance(CollectionBacking::class);
            if ($previousBacking !== null) {
                $container->instance(CollectionBacking::class, $previousBacking);
            }
        }
    }
}
