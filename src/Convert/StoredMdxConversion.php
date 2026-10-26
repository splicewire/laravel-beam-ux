<?php

namespace Splicewire\Beam\Ux\Convert;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Mdx\Convert\MdxFileConverter;
use Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser;
use Splicewire\Beam\Mdx\Lens\MdxLens;
use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Ux\Components\ComponentKind;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Files\MdxPageFile;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\Reasserter;
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
        private Reasserter $reasserter,
    ) {}

    /**
     * The pages converted, those left out and why, the components made (name => the fragment each came from), and the
     * open Release that holds them (null on a dry run, or when there was nothing to convert). One realm's pages: a Release
     * holds one realm's entries, and delivery resolves a page's tags within its realm.
     *
     * @return array{pages: list<string>, leftOut: array<string, string>, components: array<string, string>, release: ?string}
     */
    public function convert(string $root, ?Authenticatable $author, bool $write, string $realm = BeamUxEntry::REALM_SITE): array
    {
        $report = ['pages' => [], 'leftOut' => [], 'components' => [], 'release' => null];
        $fragments = MdxFileConverter::fragmentsUnder($root);
        $plans = [];
        $pages = BeamUxEntry::query()->where('realm', $realm)->where('type', UxType::Page->value)->where('format', 'mdx')->orderBy('slug')->get();
        foreach ($pages as $page) {
            $published = $this->payload($page, $page->published_version);
            if ($published === null || array_is_list($published)) {
                continue;
            }
            // A page its package still asserts, unedited, is the package's to convert: converting it here would make it a
            // site edit and stop re-assertion (EP-27 ruling). One the site already edited is the site's, and converts.
            if (str_starts_with((string) ($page->origin ?? ''), Provenance::PACKAGE_PREFIX) && $this->reasserter->isPristine($page)) {
                $report['leftOut'][$page->slug] = "the package owns it ({$page->origin}, unedited): the package converts it";

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
            $plans[] = [$page, $converted, $draft, [...$converted['placed'], ...($draft['placed'] ?? [])]];
        }

        // Each fragment a converted page places must resolve as delivery resolves its tag, in this realm, and show what the
        // fragment showed: one published Authored Component of that name, or one this conversion makes from the host's
        // fragment and puts in the Release. Resolved per fragment: two copies of one legal text are each compared.
        $components = [];
        $broken = [];
        $resolved = [];
        foreach ($plans as $plan) {
            foreach ($plan[3] as $fragment => $component) {
                if (isset($resolved[$fragment])) {
                    continue;
                }
                $resolved[$fragment] = true;
                $made = $this->resolve($root, $realm, $fragment, $component, $fragments);
                if (is_string($made)) {
                    $broken[$fragment] = $made;
                } elseif ($made !== null) {
                    $components[$component] = $made + ['fragment' => $fragment];
                }
            }
        }
        $plans = array_values(array_filter($plans, function (array $plan) use ($broken, &$report): bool {
            foreach ($plan[3] as $fragment => $component) {
                if (isset($broken[$fragment])) {
                    $report['leftOut'][$plan[0]->slug] = "it places <{$component} />: ".$broken[$fragment];

                    return false;
                }
            }

            return true;
        }));

        // A component whose only pages were left out (each placed another tag that can't resolve) is not made either.
        $placing = array_merge([], ...array_map(fn (array $plan) => $plan[3], $plans));
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

        // Each page is written in one transaction with what it needs first: the Release, then any component made from its
        // fragments. A page the write refuses (a tag a blocks body can't place here, as a package's own MDX page may have)
        // rolls all of that back and is left out; the rest go on.
        $release = null;
        $made = [];
        $report['pages'] = [];
        foreach ($plans as [$page, $converted, $draft, $placed]) {
            try {
                $page->getConnection()->transaction(function () use ($page, $converted, $draft, $placed, $components, $realm, $author, &$release, &$made): void {
                    $target = $release ?? $this->releases->create($page, 'MDX conversion', $author);
                    $new = [];
                    foreach ($placed as $component) {
                        if (isset($components[$component]) && ! isset($made[$component]) && ! isset($new[$component])) {
                            $new[$component] = $this->make($components[$component], $component, $realm, $target, $author);
                        }
                    }
                    $version = $this->publication->recordDraft($page->fresh(), $converted['doc'], 'converted from MDX source', $converted['settings'])->headVersion;
                    if ($draft !== null) {
                        $this->publication->recordDraft($page->fresh(), $draft['doc'], 'draft converted from MDX source', $draft['settings']);
                    }
                    $this->releases->putMember($target, (string) $page->getKey(), (string) $version, $author);
                    $release = $target;
                    $made += $new;
                });
                $report['pages'][] = (string) $page->slug;
            } catch (ValidationException $refused) {
                $report['leftOut'][(string) $page->slug] = 'the write refused it: '.collect($refused->errors())->flatten()->first();
            }
        }
        $report['components'] = array_intersect_key($report['components'], $made);
        ksort($report['leftOut']);
        if ($release === null) {
            return $report;
        }
        $report['release'] = (string) $release->getKey();

        return $report;
    }

    /** One fragment's Authored Component, in the realm, as a member of the Release. */
    private function make(array $made, string $component, string $realm, BeamUxRelease $release, Authenticatable $author): string
    {
        $entry = BeamUxEntry::create(['slug' => Str::kebab($component), 'title' => $made['title'], 'type' => UxType::Component,
            'format' => 'tsx', 'component_kind' => 'authored', 'realm' => $realm]);
        $version = $this->publication->recordDraft($entry, $made['doc'], 'converted from MDX fragment')->headVersion;
        $this->releases->putMember($release, (string) $entry->getKey(), (string) $version, $author);

        return (string) $entry->getKey();
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
        // Its frontmatter, with a static `export const meta` folded in as a file's is, names page settings for the version.
        [$frontmatter, $out['problem']] = $this->converter->frontmatterWithMeta($frontmatter, $converted['meta'], $converted['metaData']);
        if ($out['problem'] !== null) {
            return $out;
        }
        // Publish sets every setting from the version, so the version starts from what the page serves now and the header
        // overrides only the keys it names: a segment, a layout or a description it leaves out stays as it is.
        $served = MdxPageFile::settingsOf($page);
        try {
            if ($frontmatter === null) {
                $out['settings'] = $served;
            } else {
                $header = MdxPageFile::read("---\n{$frontmatter}\n---\n");
                $named = MdxPageFile::settingsFrom($header['fields'], $header['unknown'], $page);
                $out['settings'] = EntryPageSettingsData::validateAndCreate([
                    ...$served->toArray(),
                    ...array_intersect_key($named->toArray(), $header['fields']),
                    'fields' => [...$served->fields, ...$named->fields],
                    'unclaimed' => [...$served->unclaimed, ...$named->unclaimed],
                ]);
            }
        } catch (ValidationException $refused) {
            $out['problem'] = 'its page settings aren\'t valid: '.collect($refused->errors())->flatten()->first();
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

    /**
     * How a placed fragment's tag resolves in the realm: null when one published Authored Component already answers to it
     * (delivery finds exactly one, ComponentDelivery::candidates) and renders the fragment's blocks, the component to make
     * from the host's fragment when nothing in the realm has the name, or why the page can't place it. A same-named
     * component that isn't that one published answer (a draft, a renamed working copy, two of them) is never shadowed or
     * reused, and one whose blocks differ (a site's edit, a host copy that drifted) would change the page (ruling 1).
     *
     * @return array{doc: array, title: string}|string|null
     */
    private function resolve(string $root, string $realm, string $fragment, string $component, array $fragments): array|string|null
    {
        $named = BeamUxEntry::query()->where('realm', $realm)->where('component_kind', ComponentKind::Authored->value)
            ->where(fn ($q) => $q->where('published_component_name', $component)->orWhere('component_name', $component))->get();
        $answer = $named->count() === 1 ? $named->first() : null;
        if ($answer !== null && $answer->getAttribute('published_component_name') === $component && $answer->published_version !== null) {
            $doc = $this->converter->fragmentDoc($root, $fragments[$fragment]);
            if (is_string($doc)) {
                return "the fragment {$fragment}: {$doc}";
            }

            return MdxFileConverter::sameBlocks($this->payload($answer, $answer->published_version) ?? [], $doc)
                ? null
                : "the published <{$component} /> in realm {$realm} doesn't render what {$fragment} renders (its words, marks, links or code), so the page would change";
        }
        if ($named->isNotEmpty()) {
            return $named->count() > 1
                ? "more than one component in realm {$realm} answers to that name"
                : "the component of that name in realm {$realm} isn't published: publish it first";
        }
        if (MdxFileConverter::isLegal($fragment)) {
            // A legal text ships once, from laravel-beam-mdx (d8): never a host's own copy made from its fragment file.
            return "the shipped legal component isn't published in realm {$realm}: seed laravel-beam-mdx's legal components first";
        }
        if (BeamUxEntry::query()->whereNull('namespace')->where('slug', Str::kebab($component))->exists()) {
            return 'an entry already has the slug '.Str::kebab($component).', so the fragment can\'t become a component';
        }

        $made = $this->component($root, $fragments[$fragment], $component, $fragments);

        return is_string($made) ? "the fragment {$fragment} can't become one: {$made}" : $made;
    }

    private function payload(BeamUxEntry $page, ?string $version): ?array
    {
        $snapshot = $version === null || $page->particle === null ? null : $this->versions->version($page->particle, $version)?->snapshot;

        return is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : null;
    }
}
