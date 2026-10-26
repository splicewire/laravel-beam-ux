<?php

namespace Splicewire\Beam\Ux\Convert;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Mdx\Convert\MdxFileConverter;
use Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser;
use Splicewire\Beam\Mdx\Lens\MdxLens;
use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Files\MdxPageFile;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * A host's stored MDX pages, converted once into one previewed Release (ADR-0233 d8, d9; EP-27 rulings 1 and 4).
 *
 * Each stored MDX page that still holds source gets a new version holding blocks: its page settings from its
 * frontmatter (unclaimed keys kept, d3), its body converted as a file's is (laravel-beam-mdx's convertBody: fragments
 * placed as components, safe only when the blocks read back and the visible text are unchanged). A page with a pending
 * draft has its published version converted as the Release's member and its draft converted on top. Each fragment of
 * this host's that a page places becomes its own Authored Component, a member too, so the pages and the components they
 * place publish together. The Release is left open: an author previews it (ADR-0232) and publishes it; this never does.
 * Older versions stay as they are, read through the lens on Restore (ADR-0228 d9). A page it cannot convert is left out
 * and reported, as is one that places a fragment it cannot make a component of.
 */
final class StoredMdxConversion
{
    public function __construct(
        private MdxFileConverter $converter,
        private MdxLens $lens,
        private EntryPublication $publication,
        private ReleaseService $releases,
        private VersionStore $versions,
    ) {}

    /**
     * The pages converted, those left out and why, the components made (name => the fragment each came from), and the
     * open Release that holds them (null on a dry run, or when there was nothing to convert).
     *
     * @return array{pages: list<string>, leftOut: array<string, string>, components: array<string, string>, release: ?string}
     */
    public function convert(string $root, ?Authenticatable $author, bool $write): array
    {
        $report = ['pages' => [], 'leftOut' => [], 'components' => [], 'release' => null];
        $fragments = MdxFileConverter::fragmentsUnder($root);
        $plans = [];
        $needed = [];
        foreach (BeamUxEntry::query()->where('type', UxType::Page->value)->where('format', 'mdx')->orderBy('slug')->get() as $page) {
            $published = $this->payload($page, $page->published_version);
            if ($published === null || array_is_list($published)) {
                continue;
            }
            $converted = $this->page($page, $published, $fragments);
            $draft = null;
            if ($converted['problem'] === null && $this->publication->state($page)->draftPending) {
                $head = $page->particle?->payload;
                if (! is_array($head) || array_is_list($head)) {
                    // An author's canvas work: converting the published version on top of it would bury it.
                    $converted['problem'] = 'its pending draft already holds blocks: publish it or discard it first';
                } else {
                    $draft = $this->page($page, $head, $fragments);
                    $converted['problem'] = $draft['problem'] === null ? null : 'its pending draft: '.$draft['problem'];
                }
            }
            if ($converted['problem'] !== null) {
                $report['leftOut'][$page->slug] = $converted['problem'];

                continue;
            }
            $plans[] = [$page, $converted, $draft];
            foreach ([...$converted['placed'], ...($draft['placed'] ?? [])] as $fragment => $component) {
                if (isset($fragments[$fragment])) {
                    $needed[$component] = $fragment;
                }
            }
        }

        $components = [];
        foreach ($needed as $component => $fragment) {
            if ($this->componentNamed($component) !== null) {
                continue;
            }
            $made = $this->component($root, $fragments[$fragment], $component, $fragments);
            if (is_string($made)) {
                $report['leftOut']["fragment {$fragment}"] = $made;
                $plans = array_values(array_filter($plans, function (array $plan) use ($fragment, &$report): bool {
                    if (! array_key_exists($fragment, [...$plan[1]['placed'], ...($plan[2]['placed'] ?? [])])) {
                        return true;
                    }
                    $report['leftOut'][$plan[0]->slug] = "it places the fragment {$fragment}, which can't become a component";

                    return false;
                }));

                continue;
            }
            $components[$component] = $made + ['fragment' => $fragment];
        }

        // A component whose only pages were left out (each placed another fragment that failed) is not made either.
        $placing = [];
        foreach ($plans as [, $converted, $draft]) {
            $placing += [...$converted['placed'], ...($draft['placed'] ?? [])];
        }
        $components = array_filter($components, fn (array $made) => array_key_exists($made['fragment'], $placing));
        $report['pages'] = array_map(fn (array $plan) => (string) $plan[0]->slug, $plans);
        $report['components'] = array_map(fn (array $made) => $made['fragment'], $components);
        ksort($report['leftOut']);
        if (! $write || $plans === []) {
            return $report;
        }
        if ($author === null) {
            throw new \InvalidArgumentException('Name the author the conversion\'s Release is made by.');
        }

        $release = $this->releases->create($plans[0][0], 'MDX conversion', $author);
        foreach ($components as $component => $made) {
            $entry = BeamUxEntry::create(['slug' => Str::kebab($component), 'title' => $made['title'], 'type' => UxType::Component,
                'format' => 'tsx', 'component_kind' => 'authored', 'realm' => BeamUxEntry::REALM_SITE]);
            $version = $this->publication->recordDraft($entry, $made['doc'], 'converted from MDX fragment')->headVersion;
            $this->releases->putMember($release, (string) $entry->getKey(), (string) $version, $author);
        }
        foreach ($plans as [$page, $converted, $draft]) {
            $version = $this->publication->recordDraft($page->fresh(), $converted['doc'], 'converted from MDX source', $converted['settings'])->headVersion;
            if ($draft !== null) {
                $this->publication->recordDraft($page->fresh(), $draft['doc'], 'draft converted from MDX source', $draft['settings']);
            }
            $this->releases->putMember($release, (string) $page->getKey(), (string) $version, $author);
        }
        $report['release'] = (string) $release->getKey();

        return $report;
    }

    /** @return array{doc: array, settings: ?EntryPageSettingsData, placed: array<string, string>, problem: ?string} */
    private function page(BeamUxEntry $page, array $payload, array $fragments): array
    {
        [$frontmatter, $body] = MdxFileConverter::split(MdxBody::decode($payload));
        $converted = $this->converter->convertBody($body, $fragments);
        $out = ['doc' => $converted['doc'], 'settings' => null, 'placed' => $converted['placed'], 'problem' => $converted['problem']];
        if ($out['problem'] !== null) {
            return $out;
        }
        // Its frontmatter, with a static `export const meta` folded in as a file's is, becomes the version's page settings.
        [$frontmatter, $out['problem']] = $this->converter->frontmatterWithMeta($frontmatter, $converted['meta'], $converted['metaData']);
        if ($out['problem'] !== null || $frontmatter === null) {
            return $out;
        }
        $header = MdxPageFile::read("---\n{$frontmatter}\n---\n");
        try {
            $out['settings'] = MdxPageFile::settingsFrom($header['fields'], $header['unknown'], $page);
        } catch (\Illuminate\Validation\ValidationException $refused) {
            $out['problem'] = 'its frontmatter isn\'t valid page settings: '.collect($refused->errors())->flatten()->first();
        }

        return $out;
    }

    /**
     * A fragment of this host's as its own Authored Component (d8): its one block, declaring the component.
     *
     * @return array{doc: array, title: string}|string the component, or why it can't be one
     */
    private function component(string $root, string $relative, string $component, array $fragments): array|string
    {
        $text = (string) file_get_contents(rtrim($root, '/').'/'.$relative);
        $parsed = app(FrontmatterParser::class)->parse($text);
        $converted = $this->converter->convertBody(ltrim($parsed->content, "\n"), $fragments);
        if ($converted['problem'] !== null) {
            return $converted['problem'];
        }
        if (count($converted['doc']) !== 1 || ($converted['doc'][0]['kind'] ?? null) !== 'block') {
            return 'it is more than one block, and a component has one root: make it by hand';
        }

        return ['doc' => [$converted['doc'][0] + ['component' => ['name' => $component, 'props' => []]]],
            'title' => (string) ($parsed->fields['title'] ?? Str::headline($component))];
    }

    private function componentNamed(string $name): ?BeamUxEntry
    {
        return BeamUxEntry::query()->where('type', UxType::Component->value)
            ->where(fn ($q) => $q->where('published_component_name', $name)->orWhere('component_name', $name))->first();
    }

    private function payload(BeamUxEntry $page, ?string $version): ?array
    {
        $snapshot = $version === null || $page->particle === null ? null : $this->versions->version($page->particle, $version)?->snapshot;

        return is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : null;
    }
}
