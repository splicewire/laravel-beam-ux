<?php

namespace Splicewire\Beam\Ux\Manifest;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Ux\Manifest\Nodes\ManifestTable;
use Splicewire\Beam\Ux\Manifest\Nodes\SiteNav;

/**
 * The `@splicewire/beam-ux/site` Code Components a package's shipped MDX pages place (EP-42 S1): an MDX page holding
 * blocks may place them ({@see ManifestProfileResolver::MDX_SOURCES}).
 */
final class SiteNodeSource implements ManifestNodeSource
{
    public function profiles(): array
    {
        return ['content'];
    }

    public function nodeClassesFor(string $profile): array
    {
        return $profile === 'content' ? [SiteNav::class, ManifestTable::class] : [];
    }

    public function docAdmitsFor(string $profile): ?array
    {
        return ['html'];
    }

    public function marksFor(string $profile): array
    {
        return [];
    }

    public function manifest(string $profile): array
    {
        $manifest = (new ManifestBuilder($this))->build($profile);
        foreach ($this->nodeClassesFor($profile) as $index => $class) {
            $manifest['nodes'][$index]['attrsSchema'] = $class::attrsSchema();
        }

        return $manifest;
    }
}
