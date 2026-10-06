<?php

namespace Splicewire\Beam\Ux\Tests;

use ReflectionClass;
use Splicewire\Beam\Data\GitRepoData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Data\MirrorStatusRowData;
use Splicewire\Beam\Ux\Data\SitemapHealthRowData;

/**
 * ux-walkthrough IA-10/IA-11 (UX-09): the Developer zone's rows are glossary nouns, and a rail label is its page's h1:
 * Files · Git repos · Sitemap health. The resources were labelled "Sitemap" and "Git Repos".
 */
class DeveloperZoneLabelsTest extends TestCase
{
    public function test_the_developer_zone_resources_carry_their_glossary_labels(): void
    {
        $label = fn (string $class): string => (new ReflectionClass($class))->getAttributes(ParticleResource::class)[0]->newInstance()->label;

        $this->assertSame('Files', $label(MirrorStatusRowData::class));
        $this->assertSame('Git repos', $label(GitRepoData::class));
        $this->assertSame('Sitemap health', $label(SitemapHealthRowData::class));
    }
}
