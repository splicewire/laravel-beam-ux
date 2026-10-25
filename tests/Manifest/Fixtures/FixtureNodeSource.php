<?php

namespace Splicewire\Beam\Ux\Tests\Manifest\Fixtures;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;

/** A host ManifestNodeSource over a fixed list of `#[NodeType]` classes (EP-07 realm-bind tests). */
class FixtureNodeSource implements ManifestNodeSource
{
    /** @param  list<class-string>  $classes */
    public function __construct(private array $classes = [CalloutBlock::class]) {}

    public function profiles(): array
    {
        return ['content'];
    }

    public function nodeClassesFor(string $profile): array
    {
        return $this->classes;
    }

    public function docAdmitsFor(string $profile): ?array
    {
        return ['html'];
    }

    public function marksFor(string $profile): array
    {
        return [];
    }
}
