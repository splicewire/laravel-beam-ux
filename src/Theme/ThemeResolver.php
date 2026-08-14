<?php

namespace Splicewire\Beam\Ux\Theme;

use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Throwable;

/**
 * Computes the fully-resolved `{canvas, shell, site}` theme object per request (theme-entries-and-
 * authoring ticket 02): package default (ticket 01's {@see ThemeSchemas} `default` values) → central
 * `beam_ux_entries` theme row → tenant `beam_ux_entries` theme row (if one exists), deep-merged at the
 * per-token level, later tier wins. A host calls `resolve()` from its `HandleInertiaRequests::share()`
 * and hands the result straight to `page.props.theme` — no wiring of that call site is this ticket's
 * job (see the per-host `theme-host-wiring` tickets).
 *
 * **NEVER throws** — the whole `resolve()` body is wrapped, so a host never needs its own defensive
 * try/catch the way `RealmManifestProjector`/`CanMapBuilder` calls do today (those two ARE throwable;
 * their host call sites wrap them). This resolver self-guarantees safety instead, since `theme` is
 * unconditional shared-prop wiring with no natural "degrade to null" story a page could branch on.
 *
 * **Theme entry identity**: `namespace = 'theme'`, `slug = 'default'` — a single canonical row per
 * schema (central or tenant), the same "identify by (namespace, slug), not by `type`" shape
 * {@see BeamUxEntry::rootFor()} already uses for realm roots (ticket 03). Whether `theme` becomes its
 * own `Splicewire\Beam\Ux\Type\UxType` case is ticket 05's call (entry creation), not this one's —
 * `type` here is metadata, never the identity key.
 *
 * **Cross-schema central read**: mirrors `laravel-beam-tenancy`'s `CentralActivityLog` precedent (a
 * literal `'central'` connection name), via Eloquent's `Model::on()` rather than a dedicated model
 * subclass — guarded on that connection actually being configured, so a single-tenant host (no
 * `'central'` connection at all) transparently reads its one connection as "tenant" and the central
 * tier contributes nothing (this package has no tenancy dependency of its own, by design).
 */
class ThemeResolver
{
    /** The theme entry's build-grouping namespace — mirrors {@see BeamUxEntry::rootFor()}'s convention. */
    public const NAMESPACE = 'theme';

    /** The theme entry's slug — one canonical row per schema. */
    public const SLUG = 'default';

    private const CENTRAL_CONNECTION = 'central';

    /**
     * @param  string|null  $realm  A "sub-brand" override on top of the default cascade — e.g. a Beam-
     *                              branded section living inside an otherwise Splicewire-themed host.
     *                              Resolved as an ADDITIVE fourth tier (default → central default →
     *                              tenant default → realm), so a realm only needs to declare the tokens
     *                              it actually deviates on; anything it doesn't override still falls
     *                              through to the site's own resolved default. `null` (or a realm with
     *                              no theme row of its own) is a pure no-op — identical to calling this
     *                              with no argument at all.
     * @return array{canvas: array<string, mixed>, shell: array<string, mixed>, site: array<string, mixed>}
     */
    public function resolve(?string $realm = null): array
    {
        try {
            $theme = $this->defaults();
            $theme = array_replace_recursive($theme, $this->bodyFor($this->centralEntry(self::SLUG), self::CENTRAL_CONNECTION));
            $theme = array_replace_recursive($theme, $this->bodyFor($this->tenantEntry(self::SLUG), null));

            if ($realm !== null && $realm !== self::SLUG) {
                $theme = array_replace_recursive($theme, $this->bodyFor($this->centralEntry($realm), self::CENTRAL_CONNECTION));
                $theme = array_replace_recursive($theme, $this->bodyFor($this->tenantEntry($realm), null));
            }

            return $theme;
        } catch (Throwable) {
            return $this->defaults();
        }
    }

    /**
     * The package-default theme — every field's JSON Schema `default` value, ticket 01's ONLY genuine
     * "package default" tier. This is also what `resolve()` falls back to on ANY failure.
     *
     * @return array{canvas: array<string, mixed>, shell: array<string, mixed>, site: array<string, mixed>}
     */
    public function defaults(): array
    {
        return [
            'canvas' => $this->schemaDefaults(ThemeSchemas::canvas()),
            'shell' => $this->schemaDefaults(ThemeSchemas::shell()),
            'site' => $this->schemaDefaults(ThemeSchemas::site()),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function schemaDefaults(array $schema): array
    {
        $defaults = [];

        foreach ((array) ($schema['properties'] ?? []) as $key => $definition) {
            if (is_array($definition) && array_key_exists('default', $definition)) {
                $defaults[$key] = $definition['default'];
            }
        }

        return $defaults;
    }

    private function centralEntry(string $slug): ?BeamUxEntry
    {
        if (! is_array(config('database.connections.'.self::CENTRAL_CONNECTION))) {
            return null;
        }

        return BeamUxEntry::on(self::CENTRAL_CONNECTION)
            ->where('namespace', self::NAMESPACE)
            ->where('slug', $slug)
            ->first();
    }

    private function tenantEntry(string $slug): ?BeamUxEntry
    {
        return BeamUxEntry::query()
            ->where('namespace', self::NAMESPACE)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * The entry's body — read directly off its {@see BeamParticle}'s `payload` (NOT through
     * `StorageDriverResolver`/`ParticleStorageDriver`, which resolve the CURRENT default connection
     * with no cross-connection awareness; a theme read has no file-placement/disk-mirror concern
     * either, so the driver precedence machinery would be pure overhead here).
     *
     * @return array<string, mixed>
     */
    private function bodyFor(?BeamUxEntry $entry, ?string $connection): array
    {
        if ($entry === null || $entry->particle_id === null) {
            return [];
        }

        $particle = $connection !== null
            ? BeamParticle::on($connection)->find($entry->particle_id)
            : BeamParticle::find($entry->particle_id);

        return is_array($particle?->payload) ? $particle->payload : [];
    }
}
