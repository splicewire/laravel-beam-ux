<?php

namespace Splicewire\Beam\Ux\Support;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Theme\ThemeResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The `entry` prop a hand-written Inertia page shares so its Mainframe host can address its beam-ux
 * entry **by id** — `{id, slug, format, artifact}`, or null when the page is bound to no entry.
 *
 * ## Why this exists at all
 *
 * `@splicewire/beam-mainframe`'s `createMainframeHost` resolves the entry a page is editing from three
 * places, and only one of them can carry an id: the page's own props. The other two are the
 * `?beam_entry=<slug>` authoring override (a human types a key, never a uuid) and a host-authored,
 * COMPILE-TIME `componentToEntry` map — and no frontend map can carry a per-database uuid, because it
 * differs in every database this starter is installed into, including the fresh one it is FOR.
 *
 * So the migration off the slug-addressed `Route::beamUxEntries()` macro (ADR-0214 §6) does not happen
 * in the frontend. It happens HERE: the server, which is the only party that knows which row a page
 * means, says so in the page's props. Ported from `splicewire/www`'s helper of the same name
 * (beam-docs-satellite tickets 37 and 40).
 *
 * ## The tiebreak is written down, on purpose
 *
 * ADR-0214 §2 deleted an ambiguous `first()` that served the WRONG row — measured live at `splicewire/www`,
 * where one slug existed twice under different namespaces. This does not guess: a starter page entry is
 * `type = page` AND `namespace = config('beam.ux.namespace')` (the same namespace `AuthPagesSeeder` and
 * `App\Beam\EntryBody` already read), and anything else is not the row a route means. A slug matching
 * neither resolves to null — the page renders its own default tree (`editor/defaults.ts`) and is simply
 * not authorable, which is the honest answer on a database that was never seeded.
 *
 * ## What a SAVE address is not
 *
 * `{id, slug}` addresses a write and nothing else, and both halves of G2-BEAM-AUTHOR-ENTRY (measured on
 * beam.test 2026-09-11) were that omission. The owner authored `/`, Save said "Saved" truthfully, and no
 * reader ever saw the change — `site/home` rendered its packaged default tree because nothing in its
 * props named the compiled body. And the dock opened the JsonDoc canvas on an mdx entry, whose Save
 * replaced the mdx source with a canvas tree and blanked the public page.
 *
 * So the ref now also carries the entry's `format` (its body language — which editor may open it) and
 * its `artifact` (`{url, version}` — where to READ the compiled body). Both are things only the server
 * can know. `artifact` is null when the entry has never been authored, which the reader states as such
 * rather than telling a guest to run an artisan command.
 */
class PageEntryRef
{
    /**
     * The ref for a page entry in this host's beam-ux namespace, or null when no such row exists.
     *
     * @return array{id: string, slug: string, format: string|null, artifact: array{url: string, version: string|null}|null}|null
     */
    public static function for(string $slug): ?array
    {
        $entry = BeamUxEntry::query()
            ->where('slug', $slug)
            ->where('type', UxType::Page)
            ->where('namespace', config('beam.ux.namespace', 'starter'))
            ->first();

        if ($entry === null) {
            return null;
        }

        return [
            'id' => (string) $entry->getKey(),
            'slug' => $slug,
            'format' => self::formatValue($entry),
            'artifact' => self::artifactFor($entry),
        ];
    }

    /**
     * The ref for this host's CENTRAL THEME entry — the `namespace = theme, slug = default` row
     * `Splicewire\Beam\Ux\Theme\ThemeResolver` cascades and `ThemeSeeder` seeds — or null when the
     * database has never been seeded.
     *
     * Same job as {@see self::for()} and the same reason it exists: `/account/theme` mounts
     * `@splicewire/beam-ux`'s `<ThemeEditor entryId>`, which loads and saves over the id-addressed
     * entry-body operations, and the id of that row is a per-database uuid no frontend map can carry.
     * The theme row is identified by (namespace, slug) and NEVER by `type` — `ThemeResolver`'s own
     * docblock: "`type` here is metadata, never the identity key".
     *
     * `format` comes back as the row's real one (`css` — the codec that mirrors a saved theme to
     * `resources/beam-ux/theme/theme/default.css`), and `artifact` is null: a theme is not a compiled
     * page, it is read out of the database by the resolver on every request.
     *
     * @return array{id: string, slug: string, format: string|null, artifact: array{url: string, version: string|null}|null}|null
     */
    public static function theme(): ?array
    {
        $entry = BeamUxEntry::query()
            ->where('namespace', ThemeResolver::NAMESPACE)
            ->where('slug', ThemeResolver::SLUG)
            ->first();

        if ($entry === null) {
            return null;
        }

        return [
            'id' => (string) $entry->getKey(),
            // `getAttribute`, not `->slug`: `BeamUxEntry` declares no property for the column, so the
            // arrow form is an undefined-property access static analysis rejects — the same reason
            // {@see self::formatValue()} reads its column that way. `for()` above can use the literal
            // it was passed; this method looked the row up and has to ask the model.
            'slug' => (string) $entry->getAttribute('slug'),
            'format' => self::formatValue($entry),
            'artifact' => null,
        ];
    }

    /**
     * The entry's `UxFormat` as its wire string, or null when the column is empty.
     *
     * Read through `getAttribute` rather than `->format`: `BeamUxEntry` declares no property for it (the
     * column is cast in `casts()`), so the arrow form is an undefined-property access that static
     * analysis rejects — and the value may arrive as the enum OR as the raw string depending on how the
     * row was hydrated. Mirrors beam-ux's own `EntryBodyEnvelope::formatValue()`.
     */
    private static function formatValue(BeamUxEntry $entry): ?string
    {
        $format = $entry->getAttribute('format');

        if ($format === null || $format === '') {
            return null;
        }

        return is_object($format) && property_exists($format, 'value')
            ? (string) $format->value
            : (string) $format;
    }

    /**
     * Where to READ this entry's compiled body — the same `{url, version}` pair `PublicEntryController`
     * shares for a rendered entry, and null when there is nothing to read.
     *
     * Null in three cases, all of them "no compiled body exists at any address": the entry has never
     * been written (no particle), the artifact is not on disk, or this host has not mounted
     * `Route::beamUxSite()` and so has no artifact route to address one through. The reader turns null
     * into "this page doesn't have any content yet" — deliberately NOT the operator-facing compile
     * advice, which a guest cannot act on and which was false on this host anyway (the command reported
     * "already current 13" while `/about` sat empty).
     *
     * @return array{url: string, version: string|null}|null
     */
    private static function artifactFor(BeamUxEntry $entry): ?array
    {
        $name = (string) config('beam.ux.route_name', 'beam.ux.').'site.artifact';

        if (! Route::has($name)) {
            return null;
        }

        $artifacts = app(EntryArtifactStore::class);

        if (! $artifacts->has($entry)) {
            return null;
        }

        $version = $artifacts->version($entry);

        return [
            // The version is pinned INTO the url, not handed over beside it: the browser caches by URL
            // and an unpinned address is how a body edit never reaches a returning reader (ADR-0209 §7).
            'url' => route($name, ['entry' => $entry->getKey(), 'version' => $version]),
            'version' => $version,
        ];
    }
}
