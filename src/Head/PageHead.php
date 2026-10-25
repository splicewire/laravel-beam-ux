<?php

namespace Splicewire\Beam\Ux\Head;

use Illuminate\Http\Request;
use Splicewire\Beam\Brand\Brand;

/**
 * **The Page Head** (ADR-0234 d1): a page's title, description, canonical URL, robots and social tags, composed on the
 * server ({@see PageHeadComposer}) and rendered here, once, as the elements Inertia's server head takes as they are.
 *
 * The same elements are the renderer's `head` prop and what the root template prints inside `<x-inertia::head>`
 * (`@beamPageHead`) wherever SSR doesn't run, so a crawler that runs no script reads them. Each carries the
 * `data-inertia` key Inertia matches on, so the first navigation replaces what the template printed rather than adding
 * a second copy. The keys are the tags' own names, as an Inertia `head-key` names them.
 */
final class PageHead
{
    public function __construct(
        /** The document title, with the brand's title template applied: it applies once, here. */
        public readonly string $title,
        /** A page a guest can't read (d3): its title and `noindex`, and nothing below is printed. */
        public readonly bool $noindex = false,
        /** The page's own title, which the social tags carry beside the site's name. */
        public readonly ?string $pageTitle = null,
        public readonly ?string $siteName = null,
        public readonly ?string $description = null,
        public readonly ?string $canonical = null,
        public readonly ?string $image = null,
        /** The Open Graph type its Page Type reads as. */
        public readonly string $type = 'website',
    ) {}

    /**
     * d2: the head a host-coded page passes as its `head` prop, composed from its page row by slug. An empty list where
     * no row exists, so the root template keeps its fallback title.
     *
     * @return list<string>
     */
    public static function forPage(string $slug, ?Request $request = null): array
    {
        return app(PageHeadComposer::class)->forPage($slug, $request ?? request())?->elements() ?? [];
    }

    /**
     * What `@beamPageHead` prints in the root template: the page's head, or the brand's name as its title when the page
     * carries none (a page no head was composed for, such as an account screen).
     */
    public static function slot(mixed $page): string
    {
        $head = is_array($page) ? ($page['props']['head'] ?? null) : null;

        if (is_array($head) && $head !== [] && array_is_list($head) && array_filter($head, 'is_string') === $head) {
            return implode("\n", $head);
        }

        return '<title>'.e(Brand::for(request())->name).'</title>';
    }

    /** @return list<string> */
    public function elements(): array
    {
        $title = '<title data-inertia="title">'.e($this->title).'</title>';

        if ($this->noindex) {
            return [$title, self::element('meta', 'robots', ['name' => 'robots', 'content' => 'noindex'])];
        }

        return array_values(array_filter([
            $title,
            $this->description === null ? null : self::element('meta', 'description', ['name' => 'description', 'content' => $this->description]),
            $this->canonical === null ? null : self::element('link', 'canonical', ['rel' => 'canonical', 'href' => $this->canonical]),
            self::property('og:type', $this->type),
            $this->siteName === null ? null : self::property('og:site_name', $this->siteName),
            self::property('og:title', $this->pageTitle ?? $this->title),
            $this->description === null ? null : self::property('og:description', $this->description),
            $this->canonical === null ? null : self::property('og:url', $this->canonical),
            $this->image === null ? null : self::property('og:image', $this->image),
            // A card reads og:title, og:description and og:image itself; only its size is Twitter's own.
            self::element('meta', 'twitter:card', ['name' => 'twitter:card', 'content' => $this->image === null ? 'summary' : 'summary_large_image']),
        ]));
    }

    private static function property(string $property, string $content): string
    {
        return self::element('meta', $property, ['property' => $property, 'content' => $content]);
    }

    /** @param  array<string, string>  $attributes */
    private static function element(string $tag, string $key, array $attributes): string
    {
        $html = '<'.$tag.' data-inertia="'.e($key).'"';
        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.e($value).'"';
        }

        return $html.'>';
    }
}
