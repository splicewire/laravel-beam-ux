<?php

namespace Splicewire\Beam\Ux\Manifest;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Ux\Intake\IntakeForms;
use Splicewire\Beam\Ux\Manifest\Nodes\IntakeForm;

/**
 * EP-07d: the packaged form Code Component, in the content profile wherever laravel-beam's public intake door is on.
 * Hosted pages place it too: a sealed component with declared attrs is not code (ADR-0228 d5/d7).
 */
final class IntakeFormNodeSource implements ManifestNodeSource
{
    public function profiles(): array
    {
        return ['content'];
    }

    public function nodeClassesFor(string $profile): array
    {
        return $profile === 'content' && IntakeForms::doorEnabled() ? [IntakeForm::class] : [];
    }

    public function docAdmitsFor(string $profile): ?array
    {
        return ['html'];
    }

    public function marksFor(string $profile): array
    {
        return [];
    }

    /** @return array<string, mixed> the sealed node with its declared attrs schema */
    public function manifest(string $profile): array
    {
        $manifest = (new ManifestBuilder($this))->build($profile);
        foreach ($this->nodeClassesFor($profile) as $index => $class) {
            $manifest['nodes'][$index]['attrsSchema'] = $class::attrsSchema();
        }

        return $manifest;
    }
}
