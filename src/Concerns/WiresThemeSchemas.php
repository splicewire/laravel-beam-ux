<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresThemeSchemas
{
    /**
     * Ship the namespaced theme token schemas (theme-entries-and-authoring ticket 01) into their
     * OWN {@see FilesystemSchemaRegistry} tier — {@see ThemeSchemas::directory()}, NOT through the
     * host's `SchemaRegistry::class` binding, whose `register()` always lands in that host's FIRST
     * configured source (typically the DB tier). Package defaults must live in the FILE tier
     * specifically, so a host's later DB-tier registration of e.g. `theme.site` has something to
     * shadow (`BeamSchemaRegistry`'s whole read-order contract). `register()` is idempotent
     * (fingerprint-checked) and the artifact directory is regenerated from {@see ThemeSchemas} on
     * every boot — never hand-edit the generated `.schema.json` files.
     *
     * Host-side resolvability (JN-15 / ADR-0192 §5 — the formerly documented gap, now closed):
     * the tier is contributed into beam-core's boot-time {@see SchemaSources} registry under the
     * `theme` key, so a host's `BeamSchemaRegistry` resolves these artifacts with NO host edit —
     * appended after the configured sources (lowest precedence) unless the host's
     * `beam.core.schema.sources` names `theme` explicitly to place it. Guarded on the registry
     * class existing so beam-ux still boots against an older beam-core.
     */
    #[Chained('boot', order: 60)]
    protected function registerThemeSchemas(): void
    {
        $registry = new FilesystemSchemaRegistry(ThemeSchemas::directory());

        foreach (ThemeSchemas::all() as $schema) {
            $this->forgetStaleThemeArtifact($registry, $schema);
            $registry->register($schema);
        }

        if (class_exists(SchemaSources::class)) {
            $this->app->make(SchemaSources::class)->register(
                'theme',
                fn () => new FilesystemSchemaRegistry(ThemeSchemas::directory()),
            );
        }
    }

    /**
     * The regeneration the docblock above promises. The directory is a generated, gitignored
     * PROJECTION of {@see ThemeSchemas} — not a published registry whose readers hold instances of an
     * older shape — so when the package's declaration changes (a new token slot), the artifact left by
     * the previous boot is stale output, not a frozen contract. `register()` rightly refuses to reshape
     * a stored `$id`; left alone it made every host sharing this package directory fail to boot on the
     * first request after the change (theme.site gaining its dark slots, 2026-09-24; the same freeze was
     * cleared by hand when it gained typography). Removing only the stale file of an `$id` this package
     * declares keeps the guard intact for every other schema and every other tier.
     *
     * @param  array<string, mixed>  $schema
     */
    private function forgetStaleThemeArtifact(FilesystemSchemaRegistry $registry, array $schema): void
    {
        $id = $schema['$id'];
        $stored = $registry->get($id);

        if ($stored === null || SchemaFingerprint::of($stored) === SchemaFingerprint::of($schema)) {
            return;
        }

        foreach (glob(ThemeSchemas::directory().'/*.schema.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded) && ($decoded['$id'] ?? null) === $id) {
                @unlink($path);
            }
        }
    }
}
