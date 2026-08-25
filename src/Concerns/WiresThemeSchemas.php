<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
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
            $registry->register($schema);
        }

        if (class_exists(SchemaSources::class)) {
            $this->app->make(SchemaSources::class)->register(
                'theme',
                fn () => new FilesystemSchemaRegistry(ThemeSchemas::directory()),
            );
        }
    }
}
