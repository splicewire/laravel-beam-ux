<?php

namespace Splicewire\Beam\Ux\Head;

use Carbon\CarbonImmutable;
use Schemastud\DataSchemaOrg\ProjectionContext;
use Schemastud\DataSchemaOrg\SchemaOrgGraph;
use Schemastud\DataSchemaOrg\SchemaOrgRegistry;
use Spatie\SchemaOrg\Type;
use Splicewire\Beam\Brand\BrandData;
use Splicewire\Beam\Collections\PublicRecordData;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * A page's **Structured Data** (ADR-0234 d9, d10): one `@graph`, projected on the server through
 * `schemastud/laravel-data-schema-org`.
 *
 * The page's node is typed by its Page Type, with a BreadcrumbList from the chain on every page below the root, the
 * WebSite and its publisher on the home page, and a Record Page's Record as its main entity. The concrete schema.org
 * types live here, in the package that owns the page (flagship ADR-0050 decision 4). A Record's node comes only from a
 * projector a host registers for its Data class, {@see PublicRecordData}, which carries the Record's Collection; with
 * none registered a Record adds nothing (d12).
 *
 * Every `@id` hangs off the same base URL as the canonical (d4): `{base}/#website`, `{base}/#organization` (or
 * `#person`), and the page's own `{canonical}#webpage`, `#breadcrumb` and `#record`.
 */
final class PageGraph
{
    /** d10: the Page Types the estate uses. A further one is a line here. */
    public const PAGE_TYPES = ['WebPage', 'Article', 'CollectionPage', 'ProfilePage'];

    public function __construct(private UrlResolver $urls) {}

    /**
     * @param  list<BeamUxEntry>  $chain  root first, ending at the page
     * @param  array<string, mixed>|null  $record  a Record Page's Record, as the renderer read it
     * @return array{"@context": string, "@graph": list<array<string, mixed>>}
     */
    public function for(BeamUxEntry $entry, array $chain, string $base, string $canonical, ?string $image, BrandData $brand, ?array $record = null): array
    {
        $graph = new SchemaOrgGraph;
        $publisher = $this->publisher($base, $brand);
        $publisherRef = ['@id' => $publisher->toArray()['@id']];
        $type = in_array($entry->seo_page_type, self::PAGE_TYPES, true) ? $entry->seo_page_type : 'WebPage';
        $trail = $this->trail($chain, $base, $canonical, $brand);
        $main = $record === null ? null : $this->record($record, $base, $canonical.'#record');
        // d10: a ProfilePage's main entity is its Record on a Record Page, else the site's publisher. A Record no projector
        // maps adds nothing (d9), so its page names no main entity rather than the publisher in its place.
        $profilesPublisher = $type === 'ProfilePage' && $record === null;

        $page = self::node($type, array_filter([
            '@id' => $canonical.'#webpage',
            'url' => $canonical,
            'name' => (string) $entry->title === '' ? $brand->name : (string) $entry->title,
            'description' => self::filled($entry->seo_description),
            'image' => $image,
            'datePublished' => self::filled($entry->seo_published_date) ?? self::dateTime($entry->first_published_at),
            'dateModified' => self::dateTime($entry->last_published_at),
            'isPartOf' => ['@id' => $base.'/#website'],
            'breadcrumb' => $trail === null ? null : ['@id' => $canonical.'#breadcrumb'],
            'mainEntity' => $main !== null ? ['@id' => $canonical.'#record'] : ($profilesPublisher ? $publisherRef : null),
            ...($type === 'Article' ? ['author' => $publisherRef, 'publisher' => $publisherRef] : []),
        ], fn ($value) => $value !== null));
        $graph->add($page);

        if ($trail !== null) {
            $graph->add(self::node('BreadcrumbList', ['@id' => $canonical.'#breadcrumb', 'itemListElement' => $trail]));
        }
        if ($main !== null) {
            $graph->add($main);
        }

        $home = rtrim(parse_url($canonical, PHP_URL_PATH) ?? '/', '/') === '';
        if ($home) {
            $graph->add(self::node('WebSite', [
                '@id' => $base.'/#website', 'url' => $base.'/', 'name' => $brand->name, 'publisher' => $publisherRef,
            ]));
        }
        // The publisher's node travels wherever the page names it, so a reader of the graph never meets a bare reference.
        if ($home || $type === 'Article' || $profilesPublisher) {
            $graph->add($publisher);
        }

        return $graph->toArray();
    }

    /** An Organization built from the brand, or a Person where the host declares its publisher to be one (d9). */
    private function publisher(string $base, BrandData $brand): Type
    {
        $person = config('beam.ux.head.publisher', 'Organization') === 'Person';
        $logo = PageHeadComposer::absolute($brand->logo, $base);

        return self::node($person ? 'Person' : 'Organization', array_filter([
            '@id' => $base.'/#'.($person ? 'person' : 'organization'),
            'name' => $brand->name,
            'url' => $base.'/',
            ($person ? 'image' : 'logo') => $logo,
        ], fn ($value) => $value !== null));
    }

    /**
     * The chain as a trail of the URLs it passes through, the deepest page naming each; the realm root is the site,
     * named by the brand. The last step is the page at its own URL, which on a Record Page is its Record's. Null at the
     * root, which has no trail.
     *
     * @param  list<BeamUxEntry>  $chain
     * @return list<array<string, mixed>>|null
     */
    private function trail(array $chain, string $base, string $canonical, BrandData $brand): ?array
    {
        $steps = [];
        $page = array_pop($chain);
        foreach ($chain as $i => $node) {
            $url = $base.$this->urls->resolveChain(array_slice($chain, 0, $i + 1));
            $name = $i === 0 ? $brand->name : ((string) $node->title ?: $brand->name);
            if ($steps !== [] && end($steps)['item'] === $url) {
                $steps[array_key_last($steps)]['name'] = $name;
            } else {
                $steps[] = ['name' => $name, 'item' => $url];
            }
        }
        $last = ['name' => (string) $page?->title ?: $brand->name, 'item' => $canonical];
        if ($steps !== [] && end($steps)['item'] === $canonical) {
            $steps[array_key_last($steps)] = $last;
        } else {
            $steps[] = $last;
        }

        if (count($steps) < 2) {
            return null;
        }

        return array_map(fn (array $step, int $i) => ['@type' => 'ListItem', 'position' => $i + 1, ...$step], $steps, array_keys($steps));
    }

    /** The Record's node from the projector registered for its Data class, or null where none is. */
    private function record(array $record, string $base, string $id): ?Type
    {
        $registry = app(SchemaOrgRegistry::class);
        if (! $registry->has(PublicRecordData::class)) {
            return null;
        }

        return $registry->project(new PublicRecordData(
            id: (string) ($record['id'] ?? ''),
            collection: (string) ($record['collection'] ?? ''),
            schemaRef: (string) ($record['schemaRef'] ?? ''),
            migrationStatus: (string) ($record['migrationStatus'] ?? ''),
            fields: (array) ($record['fields'] ?? []),
        ), new ProjectionContext(baseUri: $base, id: $id));
    }

    /** @param  array<string, mixed>  $properties */
    private static function node(string $type, array $properties): Type
    {
        $class = 'Spatie\\SchemaOrg\\'.$type;
        $node = new $class;
        foreach ($properties as $property => $value) {
            $node->setProperty($property, $value);
        }

        return $node;
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function dateTime(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
