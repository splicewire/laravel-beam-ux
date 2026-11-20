<?php

namespace Splicewire\Beam\Ux\Recording;

use Splicewire\Beam\Config\Overrides\ConfigOverrideEntry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The file mirror of a section's published override layer: `config/overrides/<root>.json` (config-sections ticket 08).
 *
 * It is the serialization a Release recording writes into the SAME commit as the pages it published (ADR-0243 decision
 * 4, config root `config/overrides/`), and that `splicewire:beam:config:import` reads back into a draft on another
 * worktree. It lives in beam-ux (which depends DOWN on laravel-beam) and reads laravel-beam's {@see ConfigOverrideStore}
 * ({@see bytes()} for the recording, {@see toOps()} for the command); laravel-beam never depends on beam-ux.
 *
 * SECRETS NEVER LEAVE THE STORE. A secret pointer's {@see ConfigOverrideEntry::$value} is the DECRYPTED value at
 * runtime (ADR-0245 §6: the draft itself holds no secret value); the mirror writes a secret pointer as
 * `{"pointer": …, "op": "set", "secret": true}` with NO `value`, and {@see toOps()} never reconstructs one — so a
 * round trip (publish -> commit -> import on another Mac) leaves an existing secret override untouched.
 *
 * @phpstan-type MirrorOverride array{pointer: string, op: string, value?: mixed, secret?: bool}
 * @phpstan-type Mirror array{'$schema': string, root: string, version: int|null, overrides: list<MirrorOverride>}
 */
class ConfigOverrideMirror
{
    /** The recording-manifest root a config row records under (ADR-0243 d4), under the mirror disk. */
    public const ROOT = 'config/overrides/';

    /** The mirror file's path, relative to the recording root, for a section root. One file per root. */
    public static function pathFor(string $root): string
    {
        return self::ROOT."{$root}.json";
    }

    public function __construct(protected readonly ConfigOverrideStore $store) {}

    /**
     * The mirror document for a root's PUBLISHED layer, or null when nothing is published (a root that never published
     * leaves no mirror, so a Release of it records no config row).
     *
     * @return Mirror|null
     */
    public function document(string $root, string $connection): ?array
    {
        // Read the operator-tier layer through the RELEASE's connection (config-sections 08), so a config override just
        // published in the Release's transaction is visible on the same PDO (a host's `central` alias is a second
        // connection to the same database and would not see the uncommitted publish).
        $store = $this->store->forTier(ConfigTier::Operator)->on($connection);
        $scope = $store->scope($root);
        if ($scope === null || $scope->published_version === null) {
            return null;
        }

        $overrides = [];
        foreach ($store->publishedLayer($scope) as $pointer => $entry) {
            $overrides[] = $this->row($pointer, $entry);
        }

        return [
            '$schema' => $this->schemaRef($root),
            'root' => $root,
            'version' => $store->versionNumber($scope, $scope->published_version),
            'overrides' => $overrides,
        ];
    }

    /**
     * The mirror file's bytes for a root, or null when nothing is published. Deterministic (sorted pointers, stable
     * JSON) so an unchanged layer mirrors byte-identically and the recording sees no change.
     */
    public function bytes(string $root, string $connection): ?string
    {
        $document = $this->document($root, $connection);
        if ($document === null) {
            return null;
        }
        usort($document['overrides'], fn (array $a, array $b): int => strcmp($a['pointer'], $b['pointer']));

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * The mirror bytes a Release WOULD write for a root once `$version` is published (config-sections ticket 11): the
     * Release panel's mirror preview. The same serialization as {@see bytes()}, read from the version the member pins
     * instead of the published one, so the preview and the committed file cannot drift. Read-only: the scope is copied
     * in memory with that pin, never saved. Null when the version is null (a rollback that removes the override leaves
     * no file) or the root has no scope on this connection. Secrets carry no value, exactly as in the committed file.
     */
    public function preview(string $root, string $connection, ?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $store = $this->store->forTier(ConfigTier::Operator)->on($connection);
        $scope = $store->scope($root);
        if ($scope === null) {
            return null;
        }
        $pinned = (clone $scope)->forceFill(['published_version' => $version]);
        $overrides = [];
        foreach ($store->publishedLayer($pinned) as $pointer => $entry) {
            $overrides[] = $this->row($pointer, $entry);
        }
        usort($overrides, fn (array $a, array $b): int => strcmp($a['pointer'], $b['pointer']));

        return json_encode([
            '$schema' => $this->schemaRef($root),
            'root' => $root,
            'version' => $store->versionNumber($scope, $version),
            'overrides' => $overrides,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * The `saveDraft` ops a mirror document reconstructs — the input shape {@see ConfigOverrideStore::saveDraft()}
     * takes (`list<array{pointer, op, value?}>`).
     *
     * A SECRET row produces NO op: the file never carried the secret's value, so import must LEAVE AN EXISTING SECRET
     * OVERRIDE ALONE — not an `unset`, not an explicit null. Emitting `{pointer, set}` with no value would wipe or
     * fabricate the secret on a round trip. The same holds for any pointer the mirror omits by design: no row, no op.
     *
     * @param  Mirror|array<string, mixed>  $document
     * @return list<array{pointer: string, op: string, value?: mixed}>
     */
    public static function toOps(array $document): array
    {
        $ops = [];
        foreach (($document['overrides'] ?? []) as $override) {
            if (! is_array($override) || ! isset($override['pointer'], $override['op'])) {
                continue;
            }
            // A secret row is a marker only: import leaves the existing secret override alone (lead ruling 14:55Z).
            if (($override['secret'] ?? false) === true) {
                continue;
            }
            $op = ['pointer' => (string) $override['pointer'], 'op' => (string) $override['op']];
            // A value is carried only for a `set`; an `unset`/`null` has no value to set.
            if ($override['op'] === ConfigOverrideOp::Set->value && array_key_exists('value', $override)) {
                $op['value'] = $override['value'];
                // Replay the recorded inherit (the leaves the set does NOT own) so import reproduces the parent entry
                // exactly — the store honors an explicit inherit instead of re-deriving it from THIS host's host-owned set.
                if (isset($override['inherit']) && is_array($override['inherit'])) {
                    $op['inherit'] = array_values(array_map('strval', $override['inherit']));
                }
            }
            $ops[] = $op;
        }

        return $ops;
    }

    /**
     * One mirror row for a published entry. `value` is present only for a non-secret `set`; a secret carries
     * `secret: true` and NO value (ADR-0245 §6); `null`/`unset` carry the op alone.
     *
     * @return MirrorOverride
     */
    protected function row(string $pointer, ConfigOverrideEntry $entry): array
    {
        $row = ['pointer' => $pointer, 'op' => $entry->op->value];
        if ($entry->secret) {
            $row['secret'] = true;

            return $row;
        }
        if ($entry->op === ConfigOverrideOp::Set) {
            $row['value'] = $entry->value;
            // config-sections 08 (Spec HIGH): record the leaves this set does NOT own — host-owned children and split
            // secrets, inherited from the tier below (ConfigOverrideEntry::$inherit). Without them, a parent set imports
            // as a DESTRUCTIVE set that drops the inherited leaves, which the real normalize refuses. The inherited
            // VALUES are never written (the override never pins the host's value of the day); only the pointers are.
            if ($entry->inherit !== []) {
                $row['inherit'] = $entry->inherit;
            }
        }

        return $row;
    }

    /** A stable, secret-free schema anchor for the mirror — the section root, which names the schema the draft validates against. */
    protected function schemaRef(string $root): string
    {
        return "beam-config-section:{$root}";
    }
}
