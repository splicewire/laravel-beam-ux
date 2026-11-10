<?php

namespace Splicewire\Beam\Ux\Release\Kinds;

use Illuminate\Validation\ValidationException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The kinds a Release member can be (ADR-0243), keyed by `member_kind`. beam-ux seeds `entry`; a package whose subject a
 * Release can publish registers its own kind (beam-config's override, config-sections ticket 08).
 *
 * `Reject` on a duplicate: stored members name their kind, so a second registration under a taken key would change
 * what existing rows mean. A kind is added, never replaced.
 *
 * @api
 */
#[IsRegistry(
    root: 'beam.ux.release-member-kinds',
    entryType: ReleaseMemberKind::class,
    onKeyDuplicate: OnKeyDuplicate::Reject,
    description: 'ReleaseMemberKind implementations by member_kind — what a Release needs to know about each kind of subject it holds',
    order: 48,
)]
class ReleaseMemberKindRegistry implements Gated, Registry
{
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    public function register(RegistryKey|string|ReleaseMemberKind $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof ReleaseMemberKind) {
            $entry = $key;
            $key = $key->kind();
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /** The kind a stored member names. Refuses a kind no package registers, rather than skipping its members. */
    public function for(string $kind): ReleaseMemberKind
    {
        $found = $this->entries->tryResolve($kind);
        if (! $found instanceof ReleaseMemberKind) {
            throw ValidationException::withMessages(['release' => "This Release holds a member of kind `{$kind}`, which no installed package provides."]);
        }

        return $found;
    }

    /** @return list<ReleaseMemberKind> in registration order (`entry` first) */
    public function all(): array
    {
        return array_values(array_filter(array_map(fn ($key) => $this->entries->tryResolve($key), $this->kinds()),
            fn ($kind) => $kind instanceof ReleaseMemberKind));
    }

    /** @return list<string> */
    public function kinds(): array
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
