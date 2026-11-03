<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Docs\DocsLinkResolver;
use Splicewire\Beam\Ux\Docs\NullDocsLinkResolver;

/**
 * The null-safe docs-link seam (registry-docs ticket 01, amended ruling 1): beam-ux ships the
 * default that answers null for every key — "render no link", never a throw, never a guessed URL —
 * and the binding is a `bindIf` so a docs package's plain `bind()` supersedes it in either
 * registration order (host-bindings doctrine, container lane).
 */
class DocsLinkResolverTest extends TestCase
{
    public function test_the_seam_resolves_to_the_null_default_when_no_docs_package_rebinds(): void
    {
        $resolver = $this->app->make(DocsLinkResolver::class);

        $this->assertInstanceOf(NullDocsLinkResolver::class, $resolver);
    }

    public function test_the_null_default_answers_null_for_every_key_including_the_index_root(): void
    {
        $resolver = $this->app->make(DocsLinkResolver::class);

        $this->assertNull($resolver->urlFor('beam.particle.resources'));
        $this->assertNull($resolver->urlFor('popcorn.registries'));
        $this->assertNull($resolver->urlFor(''));
        $this->assertNull($resolver->urlFor('no.such.root'));
    }

    public function test_a_plain_bind_supersedes_the_default_the_way_a_docs_package_rebinds(): void
    {
        // The supersession lane: a later plain bind() replaces the bindIf default (the pair is
        // order-independent — bindIf never replaces, bind always does).
        $this->app->bind(DocsLinkResolver::class, fn () => new class implements DocsLinkResolver
        {
            public function urlFor(string $key): ?string
            {
                return $key === '' ? null : '/docs/registries/'.$key;
            }
        });

        $resolver = $this->app->make(DocsLinkResolver::class);

        $this->assertSame('/docs/registries/beam.particle.resources', $resolver->urlFor('beam.particle.resources'));
        $this->assertNull($resolver->urlFor(''));
    }
}
