<?php

namespace Splicewire\Beam\Ux\Release\Sinks;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Ux\Models\BeamUxRelease;

/**
 * The sinks a published Release is projected to (ADR-0243), by name. beam-ux seeds `git`, the local Git recording of the
 * Release's mirror files (ADR-0226). A host replaces a sink by registering another under its name.
 *
 * @api
 */
#[IsRegistry(
    root: 'beam.ux.publish-sinks',
    entryType: PublishSink::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'PublishSink implementations by name — the after-commit projections of a published Release (the database pin is not one)',
    order: 49,
)]
class PublishSinkRegistry implements Gated, Registry
{
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    public function register(RegistryKey|string|PublishSink $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof PublishSink) {
            $entry = $key;
            $key = $key->name();
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * Hand a kind's projection to every sink, in registration order. Each runs in its own savepoint on the Release's
     * connection; what one throws is reported and contained, so neither the publication nor the next sink sees it.
     */
    public function project(BeamUxRelease $release, ReleaseProjection $projection, Authenticatable $actor): void
    {
        foreach ($this->sinks() as $sink) {
            try {
                $release->getConnection()->transaction(fn () => $sink->project($release, $projection, $actor));
            } catch (\Throwable $failure) {
                report($failure);
            }
        }
    }

    /** @return list<PublishSink> */
    public function sinks(): array
    {
        return array_values(array_filter(array_map(fn ($key) => $this->entries->tryResolve($key), $this->names()),
            fn ($sink) => $sink instanceof PublishSink));
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->entries->relativeKeys();
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }
}
