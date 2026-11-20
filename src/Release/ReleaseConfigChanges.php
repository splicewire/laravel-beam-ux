<?php

namespace Splicewire\Beam\Ux\Release;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Data\Config\ConfigLayerData;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Data\ReleaseConfigChangeData;
use Splicewire\Beam\Ux\Data\ReleaseConfigKeyChangeData;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Recording\ConfigOverrideMirror;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;

/**
 * What each config member of a Release changes, key by key (config-sections ticket 11), for the Release panel.
 *
 * - **Which keys.** A member pins a version of its section's overrides (`version_id`) and records the published pin it
 *   replaces (`observed_pin`). The keys listed are those whose override differs between the two versions' snapshots,
 *   read through laravel-beam's version store on the Release's own connection and tier. So the list reads the same
 *   before and after publishing, and for a rollback (`version_id` is the earlier pin, or null to remove the override).
 * - **What values.** As the owner's prototype shows them (ticket 01): `published` is the key's EFFECTIVE value now,
 *   through the whole ladder, from laravel-beam's {@see ConfigSectionReader}. `after` is the new override value, null
 *   for an explicit null, or, for a dropped override, the value the key FALLS BACK to from the tier below, and `setBy`
 *   is that tier.
 * - **Who may read.** The values are `beam-config`'s: the same realm-aware read ability ({@see ConfigSectionAccess::READ})
 *   the Config area asks. A reader it refuses gets the section, package and version move with `readable: false`, no
 *   keys and no mirror.
 * - **Secrets.** A secret key never carries a value (`secretChange` says replaced, removed or unchanged), and a secret
 *   leaf inside an object value is masked to null, as the Config area masks it.
 * - **The mirror.** The file and its preview appear only when publishing really writes it
 *   ({@see ReleaseRecording::writesConfigMirror()}): central-resident, with a local mirror disk.
 *
 * Read-only: nothing here locks, publishes or writes. A member whose section is no longer registered has no entry.
 */
final class ReleaseConfigChanges
{
    /** @return list<ReleaseConfigChangeData> */
    public function of(BeamUxRelease $release, ?Authenticatable $actor = null): array
    {
        // Filter by kind() in PHP, not by the member_kind column in SQL, so a read before the kind migration still works.
        $members = $release->allMembers()->get()
            ->filter(fn (BeamUxReleaseMember $member) => $member->kind() === BeamUxReleaseMember::KIND_CONFIG)
            ->sortBy(fn (BeamUxReleaseMember $member) => $member->ref())->values();
        if ($members->isEmpty()) {
            return [];
        }

        $actor ??= auth()->user();
        $readable = $actor !== null && Gate::forUser($actor)->allows(ConfigSectionAccess::READ);
        $tier = app(TenancyContext::class)->isActive() ? ConfigTier::Tenant : ConfigTier::Operator;
        $store = app(ConfigOverrideStore::class)->forTier($tier)->on($release->getConnectionName());
        $writesMirror = app(ReleaseRecording::class)->writesConfigMirror($release);

        $changes = [];
        foreach ($members as $member) {
            $registered = app(ConfigSectionRegistry::class)->get($member->ref());
            if ($registered !== null) {
                $changes[] = $this->member($store, $tier, $member, $registered, $readable, $writesMirror, $release->getConnectionName());
            }
        }

        return $changes;
    }

    private function member(ConfigOverrideStore $store, ConfigTier $tier, BeamUxReleaseMember $member, RegisteredConfigSection $registered,
        bool $readable, bool $writesMirror, string $connection): ReleaseConfigChangeData
    {
        $root = $member->ref();
        $versions = collect($store->versionsOf($root))->keyBy(fn ($version) => (string) $version->getKey());
        $from = $member->observed_pin === null ? null : $versions->get((string) $member->observed_pin);
        $to = $member->version_id === null ? null : $versions->get((string) $member->version_id);
        $title = (string) ($registered->section::declaration()->title ?? $root);
        $mirrorPath = $writesMirror ? ConfigOverrideMirror::pathFor($root) : null;

        if (! $readable) {
            return new ReleaseConfigChangeData($root, $title, $registered->owner, $from?->version, $to?->version, false, [], $mirrorPath, null, false);
        }

        $before = self::draft($from?->snapshot);
        $after = self::draft($to?->snapshot);
        $read = app(ConfigSectionReader::class)->read($registered);
        $resolved = collect($read->keys)->keyBy(fn (ConfigKeyData $key) => $key->pointer);

        $keys = [];
        $pointers = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($pointers);
        foreach ($pointers as $pointer) {
            $key = $this->key($registered->section, (string) $pointer, $before[$pointer] ?? null, $after[$pointer] ?? null, $tier,
                $resolved->get((string) $pointer), is_array($read->values) ? $read->values : []);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return new ReleaseConfigChangeData(
            $root,
            $title,
            $registered->owner,
            $from?->version,
            $to?->version,
            collect($keys)->contains(fn (ReleaseConfigKeyChangeData $key) => $key->applies === ConfigEffect::Reload),
            $keys,
            $mirrorPath,
            $mirrorPath === null ? null : app(ConfigOverrideMirror::class)->preview($root, $connection, $member->version_id),
            true,
        );
    }

    /**
     * One key's change, or null when the two versions hold the same override for it.
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>  $section
     * @param  array<string, mixed>|null  $was
     * @param  array<string, mixed>|null  $now
     * @param  array<array-key, mixed>  $values  the section's effective values, secrets already masked by the reader
     */
    private function key(string $section, string $pointer, ?array $was, ?array $now, ConfigTier $tier, ?ConfigKeyData $resolved, array $values): ?ReleaseConfigKeyChangeData
    {
        $declared = $section::keyDeclaration(JsonPointer::segments($pointer));
        $secret = $declared->secret || (bool) ($was['secret'] ?? false) || (bool) ($now['secret'] ?? false);
        if (self::same($was, $now, $secret)) {
            return null;
        }

        $op = $now === null ? null : ConfigOverrideOp::tryFrom((string) ($now['op'] ?? ''));
        [$fallback, $fallbackTier] = $now === null ? self::fallback($resolved, $tier) : [null, $tier];
        $after = match (true) {
            $now === null => $fallback,
            $op === ConfigOverrideOp::Set => $now['value'] ?? null,
            default => null,
        };
        $published = $resolved !== null ? $resolved->value : JsonPointer::get($values, $pointer)[1];

        return new ReleaseConfigKeyChangeData(
            pointer: $pointer,
            op: $op,
            published: $secret ? null : self::masked($section, $pointer, $published),
            publishedSet: $was !== null,
            after: $secret ? null : self::masked($section, $pointer, $after),
            setBy: $fallbackTier,
            applies: $declared->effect,
            secret: $secret,
            secretChange: ! $secret ? null : match (true) {
                $now === null, ($now['op'] ?? null) !== ConfigOverrideOp::Set->value => ReleaseConfigSecretChange::Removed,
                ! self::sameSet($was, $now) || ($was['op'] ?? null) !== ConfigOverrideOp::Set->value => ReleaseConfigSecretChange::Replaced,
                default => ReleaseConfigSecretChange::Unchanged,
            },
        );
    }

    /**
     * The value a key falls back to when this tier's override is dropped, and the tier that sets it: the highest layer
     * BELOW the Release's tier that the reader resolved for the key. Without a resolved layer below (a pointer no lower
     * tier sets), the key holds nothing, and the package tier is named as its source.
     *
     * @return array{0: mixed, 1: ConfigTier}
     */
    private static function fallback(?ConfigKeyData $resolved, ConfigTier $tier): array
    {
        $rank = fn (ConfigTier $t): int => (int) array_search($t, ConfigTier::cases(), true);
        $below = collect($resolved?->layers ?? [])
            ->filter(fn (ConfigLayerData $layer) => ! $layer->removed && $rank($layer->tier) < $rank($tier))
            ->sortBy(fn (ConfigLayerData $layer) => $rank($layer->tier));
        $layer = $below->last();

        return $layer === null ? [null, ConfigTier::Package] : [$layer->value, $layer->tier];
    }

    /**
     * A value with every SECRET leaf inside it masked to null, by the section's own declarations (a key nested under a
     * declared map inherits the map's), so an object value never carries a secret below it.
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>  $section
     */
    private static function masked(string $section, string $pointer, mixed $value): mixed
    {
        if ($section::keyDeclaration(JsonPointer::segments($pointer))->secret) {
            return null;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::masked($section, JsonPointer::from([...JsonPointer::segments($pointer), (string) $key]), $child);
        }

        return $value;
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

    /** @return array<string, array<string, mixed>> */
    private static function draft(mixed $snapshot): array
    {
        $draft = is_array($snapshot) ? ($snapshot['draft'] ?? []) : [];

        return is_array($draft) ? $draft : [];
    }
}
