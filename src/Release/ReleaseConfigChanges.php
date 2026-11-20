<?php

namespace Splicewire\Beam\Ux\Release;

use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Data\ReleaseConfigChangeData;
use Splicewire\Beam\Ux\Data\ReleaseConfigKeyChangeData;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Recording\ConfigOverrideMirror;
use Throwable;

/**
 * What each config member of a Release changes, key by key (config-sections ticket 11), for the Release panel.
 *
 * A member pins a version of its section's overrides (`version_id`) and records the published pin it replaces
 * (`observed_pin`). The diff is between those two versions' snapshots, read through laravel-beam's version store on the
 * Release's own connection and tier, so it reads the same before and after publishing, and for a rollback (whose
 * `version_id` is the earlier pin, or null to remove the override).
 *
 * Secrets: a snapshot never holds a secret's value (it is stored encrypted, apart), and neither does this projection.
 * A secret key says only `replaced`, `removed` or `unchanged`.
 *
 * Read-only: nothing here locks, publishes or writes. A section that is no longer registered, or a store this realm
 * cannot read, yields no entry rather than failing the whole Release read.
 */
final class ReleaseConfigChanges
{
    /** @return list<ReleaseConfigChangeData> */
    public function of(BeamUxRelease $release): array
    {
        $members = $release->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)->orderBy('member_ref')->get();
        if ($members->isEmpty()) {
            return [];
        }

        $tier = app(TenancyContext::class)->isActive() ? ConfigTier::Tenant : ConfigTier::Operator;
        $store = app(ConfigOverrideStore::class)->forTier($tier)->on($release->getConnectionName());
        $changes = [];
        foreach ($members as $member) {
            $change = $this->member($store, $tier, $member, $release->getConnectionName());
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    private function member(ConfigOverrideStore $store, ConfigTier $tier, BeamUxReleaseMember $member, string $connection): ?ReleaseConfigChangeData
    {
        $root = $member->ref();
        $registered = app(ConfigSectionRegistry::class)->get($root);
        if ($registered === null) {
            return null;
        }

        try {
            $versions = collect($store->versionsOf($root))->keyBy(fn ($version) => (string) $version->getKey());
        } catch (Throwable) {
            return null;
        }
        $from = $member->observed_pin === null ? null : $versions->get((string) $member->observed_pin);
        $to = $member->version_id === null ? null : $versions->get((string) $member->version_id);
        $before = self::draft($from?->snapshot);
        $after = self::draft($to?->snapshot);

        $keys = [];
        $pointers = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($pointers);
        foreach ($pointers as $pointer) {
            $key = $this->key($registered->section, (string) $pointer, $before[$pointer] ?? null, $after[$pointer] ?? null, $tier);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        // Only a CENTRAL Release writes the mirror file (the recording refuses while tenancy is active): a tenant Release
        // shows no file, which the panel reads as "database history only".
        $central = $tier === ConfigTier::Operator;
        try {
            $mirror = $central ? app(ConfigOverrideMirror::class)->preview($root, $connection, $member->version_id) : null;
        } catch (Throwable) {
            $mirror = null;
        }

        return new ReleaseConfigChangeData(
            $root,
            (string) ($registered->section::declaration()->title ?? $root),
            $registered->owner,
            $from?->version,
            $to?->version,
            collect($keys)->contains(fn (ReleaseConfigKeyChangeData $key) => $key->applies === ConfigEffect::Reload),
            $keys,
            $central ? ConfigOverrideMirror::pathFor($root) : null,
            $mirror,
        );
    }

    /**
     * One key's change, or null when the two versions hold the same thing for it.
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>  $section
     * @param  array<string, mixed>|null  $was
     * @param  array<string, mixed>|null  $now
     */
    private function key(string $section, string $pointer, ?array $was, ?array $now, ConfigTier $tier): ?ReleaseConfigKeyChangeData
    {
        $declared = $section::keyDeclaration(JsonPointer::segments($pointer));
        $secret = $declared->secret || (bool) ($was['secret'] ?? false) || (bool) ($now['secret'] ?? false);
        if (self::same($was, $now, $secret)) {
            return null;
        }

        return new ReleaseConfigKeyChangeData(
            pointer: $pointer,
            op: $now === null ? null : ConfigOverrideOp::tryFrom((string) ($now['op'] ?? '')),
            published: $secret ? null : self::value($was),
            publishedSet: $was !== null,
            after: $secret ? null : self::value($now),
            setBy: $now === null ? 'inherited' : $tier->value,
            applies: $declared->effect,
            secret: $secret,
            secretChange: ! $secret ? null : match (true) {
                $now === null => 'removed',
                ($now['op'] ?? null) === ConfigOverrideOp::Set->value && ! self::sameSet($was, $now) => 'replaced',
                default => 'unchanged',
            },
        );
    }

    /**
     * Whether two snapshot entries hold the same override. A secret's value is not in the snapshot, so a secret set
     * again reads as a change through its set time (or revision), never through a value.
     *
     * @param  array<string, mixed>|null  $was
     * @param  array<string, mixed>|null  $now
     */
    private static function same(?array $was, ?array $now, bool $secret): bool
    {
        if ($was === null || $now === null) {
            return $was === $now;
        }
        if (($was['op'] ?? null) !== ($now['op'] ?? null)) {
            return false;
        }

        return $secret ? self::sameSet($was, $now) : (($was['value'] ?? null) === ($now['value'] ?? null));
    }

    /**
     * @param  array<string, mixed>|null  $was
     * @param  array<string, mixed>  $now
     */
    private static function sameSet(?array $was, array $now): bool
    {
        return $was !== null && ($was['setAt'] ?? null) === ($now['setAt'] ?? null)
            && ($was['revision'] ?? null) === ($now['revision'] ?? null);
    }

    /** @param  array<string, mixed>|null  $entry */
    private static function value(?array $entry): mixed
    {
        return $entry !== null && ($entry['op'] ?? null) === ConfigOverrideOp::Set->value ? ($entry['value'] ?? null) : null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function draft(mixed $snapshot): array
    {
        $draft = is_array($snapshot) ? ($snapshot['draft'] ?? []) : [];

        return is_array($draft) ? $draft : [];
    }
}
