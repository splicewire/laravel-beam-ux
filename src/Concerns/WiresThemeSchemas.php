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
            // The sweep decides what to remove from what the directory holds, then registers. Another process
            // registering in between made this boot throw a frozen-schema conflict, or had its artifact swept
            // under it. Inside the registry's lock the two are one step for every other process (Fix B, CL0363Y).
            $registry->withLock(function (FilesystemSchemaRegistry $locked) use ($schema): void {
                $this->forgetStaleThemeArtifact($locked, $schema);
                $locked->register($schema);
            });
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
            // The glob lists the directory as it was; another host sharing it may have removed the file since,
            // or the entry is a link whose target is gone. A file that is gone holds no stale `$id` to forget,
            // and its read's warning must not fail the boot (Fix B, calcucrypt root ruling 0bc3e9ce). ONLY that is
            // skipped: an entry that is still there but cannot be read (unreadable, a directory, any I/O error)
            // is a broken install, and fails loudly with the read's own reason.
            error_clear_last();
            $contents = @file_get_contents($path);
            if ($contents === false || is_dir($path)) {
                clearstatcache(true, $path);
                if (! file_exists($path)) {
                    continue; // gone, or a link to nothing (file_exists follows the link)
                }

                throw new \RuntimeException(
                    "Cannot read the theme-schema artifact [{$path}]: ".(error_get_last()['message'] ?? 'not a file'),
                );
            }

            $decoded = json_decode($contents, true);

            if (is_array($decoded) && ($decoded['$id'] ?? null) === $id) {
                @unlink($path);
            }
        }
    }
}
