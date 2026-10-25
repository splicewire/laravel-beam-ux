<?php

namespace Splicewire\Beam\Ux\Manifest;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Mdx\Kit\MdxKitNodeSource;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0228 d7 (EP-07a, part b) — resolves the one Manifest Profile an entry is edited and saved against.
 * The vocabulary is the intrinsic-HTML base ({@see HtmlManifestProfile}); `x-admits-code` is decided by
 * the entry's residency ({@see EntryResidency}, ux-0227 d1): a central-resident entry admits code, a
 * tenant-owned entry does not.
 *
 * **Each realm serves one profile** (EP-07, WP-EP07). Onto the base go beam-ux's packaged editor nodes, the nodes
 * packages contribute (container tag {@see self::PACKAGE_SOURCES}, e.g. the shipped auth islands), and the host's
 * placeable `#[NodeType]` components for the entry's realm: the {@see ManifestNodeSource} that
 * `beam.ux.manifest.realms.{realm}` names, else the app-wide bound one (the host that has not bound its realms keeps the
 * vocabulary it had). The palette, the inspector and the save all read this one profile.
 */
final class ManifestProfileResolver
{
    /** The container tag a package registers its {@see ManifestNodeSource} under, composed into every realm's profile. */
    public const PACKAGE_SOURCES = 'beam-ux.manifest-node-sources';

    public function __construct(private EntryResidency $residency) {}

    /**
     * @return array<string, mixed> a v1 manifest with an `x-admits-code` flag the save check reads.
     */
    public function resolve(BeamUxEntry $entry, string $profile = 'content'): array
    {
        $base = HtmlManifestProfile::manifest();
        $nodes = array_merge($base['nodes'], (new PackagedEditorNodeSource)->manifest($profile)['nodes']);

        // ADR-0233 d7 — on a host whose MDX pages hold blocks (`beam.ux.mdx.holds_blocks`), an MDX page's kit
        // tags are the beam-mdx kit's Code Components, declared by splicewire/laravel-beam-mdx, so its body is
        // checked against them like any other block. A host that keeps MDX as source serves the manifest it
        // always has.
        if ($entry->format === UxFormat::Mdx && config('beam.ux.mdx.holds_blocks')
            && in_array($profile, ($kit = new MdxKitNodeSource)->profiles(), true)) {
            $have = array_column($nodes, 'name');
            foreach ($kit->manifest($profile)['nodes'] as $node) {
                if (! in_array($node['name'], $have, true)) {
                    $nodes[] = $node;
                }
            }
        }

        // The nodes packages contribute, then the host's placeable `#[NodeType]` components for this entry's realm.
        // Ordered-array composition (ADR-0112): a later source may NOT redeclare an earlier name.
        foreach ([...app()->tagged(self::PACKAGE_SOURCES), $this->realmSource($entry)] as $source) {
            if ($source === null || ! in_array($profile, $source->profiles(), true)) {
                continue;
            }
            $have = array_column($nodes, 'name');
            foreach (self::nodesOf($source, $profile) as $node) {
                if (! in_array($node['name'], $have, true)) {
                    $nodes[] = $node;
                }
            }
        }

        return [
            'profile' => $base['profile'],
            'version' => 1,
            'doc' => $base['doc'],
            'nodes' => $nodes,
            'marks' => $base['marks'],
            'x-prose' => $base['x-prose'],
            'x-admits-code' => $this->residency->isCentralResident($entry),
            ...(($collections = app(\Splicewire\Beam\Ux\Bindings\ProfileCollections::class)->manifest($entry, $profile)) === [] ? [] : ['x-collections' => $collections]),
        ];
    }

    /**
     * The host's placeable components for the entry's realm: the source `beam.ux.manifest.realms.{realm}` names, else the
     * app-wide bound {@see ManifestNodeSource}, else none.
     */
    public function realmSource(BeamUxEntry $entry): ?ManifestNodeSource
    {
        $class = config('beam.ux.manifest.realms.'.($entry->realm ?? BeamUxEntry::REALM_SITE));
        if (is_string($class) && $class !== '') {
            $source = app($class);
            if (! $source instanceof ManifestNodeSource) {
                throw new \InvalidArgumentException("beam.ux.manifest.realms names [{$class}], which is not a ManifestNodeSource.");
            }

            return $source;
        }

        return app()->bound(ManifestNodeSource::class) ? app(ManifestNodeSource::class) : null;
    }

    /**
     * A source's nodes for a profile: its own manifest when it shapes one (a sealed island's attrs schema), else the
     * blockdoc {@see ManifestBuilder}'s.
     *
     * @return list<array<string, mixed>>
     */
    public static function nodesOf(ManifestNodeSource $source, string $profile): array
    {
        return method_exists($source, 'manifest') ? $source->manifest($profile)['nodes'] : (new ManifestBuilder($source))->build($profile)['nodes'];
    }
}
