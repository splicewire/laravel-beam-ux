<?php

namespace Splicewire\Beam\Ux\Manifest;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Ux\Manifest\Nodes\DemoFeatureRow;
use Splicewire\Beam\Ux\Manifest\Nodes\DemoHero;
use Splicewire\Beam\Ux\Manifest\Nodes\RichBlockdoc;
use Splicewire\Beam\Ux\Manifest\Nodes\RichMarkdown;

/** The components placed by beam-ux's own author-create defaults. Host sources stay separate. */
final class PackagedEditorNodeSource implements ManifestNodeSource
{
    public function profiles(): array
    {
        return ['content'];
    }

    public function nodeClassesFor(string $profile): array
    {
        return $profile === 'content' ? [DemoHero::class, DemoFeatureRow::class, RichMarkdown::class, RichBlockdoc::class] : [];
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
            // ManifestBuilder adds a nullable id attribute by default. These sealed islands have
            // NO component props; node identity lives on the JsonNode, outside its props array.
            $manifest['nodes'][$index]['attrsSchema'] = $class::attrsSchema();
        }

        return $manifest;
    }
}
