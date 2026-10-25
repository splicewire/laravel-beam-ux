<?php

namespace Splicewire\Beam\Ux\Head;

use Illuminate\Http\Request;
use Splicewire\Beam\Brand\Brand;
use Splicewire\Beam\Brand\BrandData;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Support\PageEntryRef;

/**
 * Composes a page's {@see PageHead} from its row: the title and SEO Fields Publish wrote there (ADR-0234 d6), the brand,
 * and the canonical URL off the sitemap's base-URL port (d4), so it agrees with the sitemap and a Site Host's pages
 * name the Site Host.
 */
final class PageHeadComposer
{
    public function __construct(
        private SitemapBaseUrlResolver $baseUrl,
        private PublicEntryGate $gate,
        private EntryPathResolver $paths,
        private PageGraph $graph,
    ) {}

    /**
     * The head of a page served at `$path` (a Record Page's is its Record's). A restricted chain gets its title and
     * `noindex` only (d3), so nothing else about a page a guest can't read is composed, its Structured Data included.
     *
     * @param  list<BeamUxEntry>  $chain  root first, ending at the page: its breadcrumbs
     * @param  array<string, mixed>|null  $record  a Record Page's Record
     */
    public function compose(BeamUxEntry $entry, string $path, bool $restricted, ?Request $request = null, array $chain = [], ?array $record = null): PageHead
    {
        $brand = Brand::for($request);
        $pageTitle = (string) $entry->title;
        $title = self::brandTitle($pageTitle, $brand);

        if ($restricted) {
            return new PageHead(title: $title, noindex: true);
        }

        $base = rtrim($this->baseUrl->baseUrl(), '/');
        $canonical = $base.'/'.ltrim($path, '/');
        $image = self::absolute(self::filled($entry->seo_social_image), $base);

        return new PageHead(
            title: $title,
            pageTitle: $pageTitle === '' ? $brand->name : $pageTitle,
            siteName: $brand->name,
            description: self::filled($entry->seo_description),
            canonical: $canonical,
            image: $image,
            type: match ($entry->seo_page_type) {
                'Article' => 'article',
                'ProfilePage' => 'profile',
                default => 'website',
            },
            structuredData: $this->graph->installed()
                ? $this->graph->for($entry, $chain === [] ? [$entry] : $chain, $base, $canonical, $image, $brand, $record)
                : null,
        );
    }

    /** d2: a host-coded page's head, from its page row, at the URL the host serves it on. */
    public function forPage(string $slug, Request $request): ?PageHead
    {
        $entry = PageEntryRef::row($slug);
        if ($entry === null) {
            return null;
        }

        // Inside a Preview that holds this page, its head reads the Preview's page settings and chain, as the renderer's
        // does (ADR-0232 d10; EP-18: a host's own page, such as a starter's home).
        $preview = $request->attributes->get('beam.ux.preview');
        if ($preview instanceof \Splicewire\Beam\Ux\Preview\PreviewContext && $preview->admitsDraft((string) $entry->getKey())) {
            $projected = $preview->project($entry);
            if ($projected !== null) {
                $chain = app(\Splicewire\Beam\Ux\Containment\PreviewEntryPathResolver::class)
                    ->chainForEntry((string) $entry->getKey(), (string) $entry->realm, $preview);

                return $this->compose($projected, $request->path(), $chain === null || $this->gate->isRestricted($chain), $request, $chain ?? []);
            }
        }

        // A broken ancestry denies, as it does for the renderer, so it reads as restricted.
        $chain = $this->paths->ancestry($entry);

        return $this->compose($entry, $request->path(), $chain === null || $this->gate->isRestricted($chain), $request, $chain ?? []);
    }

    /**
     * beam-inertia's `brandTitle`, on the server. The client passes a server-composed title through, so the template
     * applies once, here; both tokens are replaced in one pass, so a title holding `:name` keeps it.
     */
    public static function brandTitle(string $title, BrandData $brand): string
    {
        if ($title === '') {
            return $brand->name;
        }
        if ($brand->titleTemplate !== null) {
            return strtr($brand->titleTemplate, [':title' => $title, ':name' => $brand->name]);
        }

        return $brand->name === '' ? $title : "{$title} - {$brand->name}";
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** A media-library image is usually a path; a link preview, and a graph's reader, needs the whole URL. */
    public static function absolute(?string $url, string $base): ?string
    {
        return $url === null || preg_match('#^(https?:)?//#i', $url) === 1 ? $url : $base.'/'.ltrim($url, '/');
    }
}
