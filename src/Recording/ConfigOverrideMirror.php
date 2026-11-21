<?php

namespace Splicewire\Beam\Ux\Recording;

use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\JsonPointer;
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

        return [
            '$schema' => $this->schemaRef($root),
            'root' => $root,
            'version' => $store->versionNumber($scope, $scope->published_version),
            'overrides' => $this->overridesOf($store->publishedLayer($scope), $this->sectionOf($root)),
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

        return json_encode([
            '$schema' => $this->schemaRef($root),
            'root' => $root,
            'version' => $store->versionNumber($scope, $version),
            'overrides' => $this->overridesOf($store->publishedLayer($pinned), $this->sectionOf($root)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * The `saveDraft` ops a mirror document reconstructs — the input shape {@see ConfigOverrideStore::saveDraft()}
     * takes (`list<array{pointer, op, value?}>`).
     *
     * A SECRET row produces NO op: the file never carried the secret's value, so import must LEAVE AN EXISTING SECRET
     * OVERRIDE ALONE — not an `unset`, not an explicit null. Emitting `{pointer, set}` with no value would wipe or
     * fabricate the secret on a round trip. The same holds for any pointer the mirror omits by design: no row, no op.
     * A row whose pointer the CURRENT declaration makes secret (by the ancestor rule) is treated exactly like a marker
     * row and produces no op, so importing an OLD file that carries a now-secret key's plaintext does not write that
     * stale value in as the secret (config-sections 11 r5): `$section` is the registered section class, or null for a
     * root this host no longer registers (then the stored `secret` flag alone decides).
     *
     * A PARENT `set` with a secret marker row BELOW it replays as one `set` per owned leaf, not as one parent `set`
     * (config-sections 11, lead RT-1425). laravel-beam's saveDraft drops a parent's split-out secret children on any
     * parent op (ConfigOverrideStore.php:209), so a parent `set` would DROP a target secret split out of that parent,
     * breaking the rule above. Child ops never drop it: the public keys update and the secret override survives. A leaf
     * that is the EMPTIED residue of a split (an intermediate map left `[]` after its secret child was taken out, which
     * {@see JsonPointer::leaves()} still counts as a leaf) is NOT an owned value: replaying it as a parent op would make
     * saveDraft drop the target's secret split out of THAT map (config-sections 11 r5 HIGH), so no op is emitted at or
     * below a marker, nor one that is an ancestor of (or equal to) a marker or an inherited pointer. The trade, by
     * design: a public key the target holds under that parent but the file lacks is NOT removed (child sets update, they
     * do not replace the whole object). Every other row replays exactly as before.
     *
     * @param  Mirror|array<string, mixed>  $document
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>|null  $section
     * @return list<array{pointer: string, op: string, value?: mixed}>
     */
    public static function toOps(array $document, ?string $section = null): array
    {
        $ops = [];
        $rows = array_values(array_filter($document['overrides'] ?? [], fn ($row): bool => is_array($row) && isset($row['pointer'], $row['op'])));
        $isMarker = fn (array $row): bool => ($row['secret'] ?? false) === true || self::declaredSecret($section, (string) $row['pointer']);
        $markers = array_values(array_map(fn (array $row): string => (string) $row['pointer'], array_filter($rows, $isMarker)));
        foreach ($rows as $override) {
            // A secret row — stored secret, or secret by the current declaration — is a marker only: import leaves the
            // existing secret override alone (lead ruling 14:55Z; config-sections 11 r5 old-file plaintext).
            if ($isMarker($override)) {
                continue;
            }
            $at = (string) $override['pointer'];
            $below = array_values(array_filter($markers, fn (string $marker): bool => str_starts_with($marker, $at.'/')));
            if ($override['op'] === ConfigOverrideOp::Set->value && is_array($override['value'] ?? null) && $below !== []) {
                // Protect the markers below $at and the row's inherited pointers (absolute): never set a leaf at/below
                // one, nor a leaf that is an ancestor of (or equal to) one — that leaf is split residue, not a value.
                $guards = $below;
                foreach (is_array($override['inherit'] ?? null) ? $override['inherit'] : [] as $relative) {
                    if (is_string($relative)) {
                        $guards[] = $at.$relative;
                    }
                }
                foreach (JsonPointer::leaves($override['value']) as $leaf) {
                    $pointer = $at.$leaf;
                    $atOrBelowMarker = array_filter($below, fn (string $m): bool => $pointer === $m || str_starts_with($pointer, $m.'/')) !== [];
                    $ancestorOfGuard = array_filter($guards, fn (string $g): bool => $g === $pointer || str_starts_with($g, $pointer.'/')) !== [];
                    if ($atOrBelowMarker || $ancestorOfGuard) {
                        continue;
                    }
                    $ops[] = ['pointer' => $pointer, 'op' => ConfigOverrideOp::Set->value, 'value' => JsonPointer::get($override['value'], $leaf)[1]];
                }

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
     * Every mirror row of a published layer (CONTRACT-11 rev 4, section 2b), sorted by pointer. One pointer is written
     * once: when an entry's own marker row and a marker row derived from a parent's secret leaf name the same pointer
     * (a secret split out at save time is its own entry), the marker is kept, so a secret is never written twice nor
     * written with a value.
     *
     * @param  array<string, ConfigOverrideEntry>  $layer  pointer => entry
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>|null  $section
     * @return list<MirrorOverride>
     */
    protected function overridesOf(array $layer, ?string $section): array
    {
        $rows = [];
        foreach ($layer as $pointer => $entry) {
            foreach ($this->rows((string) $pointer, $entry, $section) as $row) {
                $existing = $rows[$row['pointer']] ?? null;
                if ($existing === null || (($row['secret'] ?? false) === true && ($existing['secret'] ?? false) !== true)) {
                    $rows[$row['pointer']] = $row;
                }
            }
        }
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    /**
     * The mirror rows for one published entry, in the three forms CONTRACT-11 rev 4 section 2b allows:
     *
     * - **A secret marker row**, `{pointer, op, secret: true}` with no value: when the STORED entry is secret, or the
     *   section's CURRENT declaration makes the pointer itself secret (a key under a declared map or object inherits
     *   it). A version saved while the key was declared non-secret still holds plaintext with `secret: false`; the
     *   declaration masks it (config-sections 11, build-qa HIGH), in the committed file and the preview alike.
     * - **A parent row** `{pointer, op: set, value, inherit}`: a `set` whose value holds leaves the CURRENT declaration
     *   makes secret keeps its PUBLIC parts (Codex re-verify HIGH: the whole parent used to become a marker, so import
     *   lost its public siblings). Each secret leaf is taken out of `value` and listed in `inherit` as a pointer
     *   RELATIVE to the row's pointer (laravel-beam's form, with the entry's own inherit), so import rebuilds the public
     *   keys and leaves each secret as the target holds it; and each secret leaf gets its own marker row (ABSOLUTE
     *   pointer) so the file says the Release sets it.
     * - **A plain row**: `{pointer, op: set, value}` (with the entry's own `inherit` when it has one), or `{pointer, op}`
     *   for `null`.
     *
     * An entry of a section this host no longer registers has no declaration to read, so its stored flags alone decide.
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>|null  $section
     * @return list<MirrorOverride>
     */
    protected function rows(string $pointer, ConfigOverrideEntry $entry, ?string $section = null): array
    {
        $marker = fn (string $at, ConfigOverrideOp $op): array => ['pointer' => $at, 'op' => $op->value, 'secret' => true];
        // The ancestor rule, one rule for a row's own pointer and for a leaf inside a value ({@see secretLeaves()}): a
        // pointer at or below a declared-secret key is a marker. The nearest-attribute rule disagreed when a secret
        // object's child carried its own `#[ConfigKey]` — it emitted a plain row WITH the child's value next to the
        // object's marker, plaintext in the file that the strict parser then refuses (config-sections 11 r5 MEDIUM).
        if ($entry->secret || self::declaredSecret($section, $pointer)) {
            return [$marker($pointer, $entry->op)];
        }
        if ($entry->op !== ConfigOverrideOp::Set) {
            return [['pointer' => $pointer, 'op' => $entry->op->value]];
        }

        $value = $entry->value;
        $inherit = $entry->inherit;
        $markers = [];
        if ($section !== null && is_array($value)) {
            foreach (self::secretLeaves($section, JsonPointer::segments($pointer), $value) as [$relative, $leaf]) {
                $value = self::without($value, JsonPointer::segments($relative));
                $inherit[] = $relative;
                $markers[] = $marker($pointer.$relative, $leaf === null ? ConfigOverrideOp::Null : ConfigOverrideOp::Set);
            }
        }

        // A map whose only children were secret or host-owned is `[]` after they are taken out — here, or already in the
        // store, which splits a secret child out before the serializer sees the parent. It carries `inherit` for those
        // children, so an empty value WITH inherit is an emptied map: emit the object `{}` it is, so the grammar's
        // "`value` present on a non-secret set" holds with the right JSON type and the file parses under section 2b
        // (config-sections 11 r5). Import yields no op for it; the command treats a no-op document as a no-op, not an error.
        if ($value === [] && $inherit !== []) {
            $value = (object) [];
        }
        $row = ['pointer' => $pointer, 'op' => $entry->op->value, 'value' => $value];
        // config-sections 08 (Spec HIGH): record the leaves this set does NOT own (host-owned children and split
        // secrets, inherited from the tier below), now also the declared-secret leaves taken out above. Without them, a
        // parent set imports as a DESTRUCTIVE set that drops the inherited leaves. Only the pointers are written.
        $inherit = array_values(array_unique(array_map('strval', $inherit)));
        sort($inherit, SORT_STRING);
        if ($inherit !== []) {
            $row['inherit'] = $inherit;
        }

        return [$row, ...$markers];
    }

    /**
     * The entry's OWN row (the first of {@see rows()}): its marker, its parent row or its plain row, without the marker
     * rows derived from its secret leaves. Kept for callers that serialize one entry (ticket 08's import test).
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>|null  $section
     * @return MirrorOverride
     */
    protected function row(string $pointer, ConfigOverrideEntry $entry, ?string $section = null): array
    {
        return $this->rows($pointer, $entry, $section)[0];
    }

    /**
     * Whether the CURRENT declaration makes a pointer secret by the ANCESTOR rule: the pointer itself, or any ancestor
     * below the root, carries `#[ConfigKey(secret: true)]`. The one secrecy rule for a row's own pointer and for a leaf
     * inside a value (what {@see secretLeaves()} applies top-down), so a key under a declared-secret map or object is
     * never written with a value. A null section (a root this host no longer registers) has no declaration to read.
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>|null  $section
     */
    protected static function declaredSecret(?string $section, string $pointer): bool
    {
        if ($section === null) {
            return false;
        }
        $segments = JsonPointer::segments($pointer);
        for ($length = count($segments); $length >= 1; $length--) {
            if ($section::keyDeclaration(array_slice($segments, 0, $length))->secret) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declared-secret leaves inside a `set` value, each as [pointer RELATIVE to the row, its value]. A leaf whose
     * own declaration (or an ancestor's below the row) is secret is reported once, whole: what lies under it is not
     * walked again.
     *
     * @param  class-string<\Splicewire\Beam\Config\ConfigSection>  $section
     * @param  list<string>  $base  the row pointer's segments
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $below  the segments walked below the row
     * @return list<array{0: string, 1: mixed}>
     */
    protected static function secretLeaves(string $section, array $base, array $value, array $below = []): array
    {
        $found = [];
        foreach ($value as $key => $child) {
            $path = [...$below, (string) $key];
            if ($section::keyDeclaration([...$base, ...$path])->secret) {
                $found[] = [JsonPointer::from($path), $child];

                continue;
            }
            if (is_array($child)) {
                $found = [...$found, ...self::secretLeaves($section, $base, $child, $path)];
            }
        }

        return $found;
    }

    /**
     * `$value` with the key at `$segments` removed (a missing path leaves it as it is).
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $segments
     * @return array<array-key, mixed>
     */
    protected static function without(array $value, array $segments): array
    {
        $key = array_shift($segments);
        if ($key === null || ! array_key_exists($key, $value)) {
            return $value;
        }
        if ($segments === []) {
            unset($value[$key]);

            return $value;
        }
        if (is_array($value[$key])) {
            $value[$key] = self::without($value[$key], $segments);
        }

        return $value;
    }

    /** @return class-string<\Splicewire\Beam\Config\ConfigSection>|null the registered section class for a root, or null */
    protected function sectionOf(string $root): ?string
    {
        return app(ConfigSectionRegistry::class)->get($root)?->section;
    }

    /** A stable, secret-free schema anchor for the mirror — the section root, which names the schema the draft validates against. */
    protected function schemaRef(string $root): string
    {
        return "beam-config-section:{$root}";
    }
}
