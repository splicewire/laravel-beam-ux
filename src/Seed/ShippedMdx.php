<?php

namespace Splicewire\Beam\Ux\Seed;

/**
 * A package's shipped MDX page as the host stores it (ADR-0233 d9, "a package runs it over the pages it ships"; EP-42 S1).
 *
 * On a host whose MDX pages hold blocks (`beam.ux.mdx.holds_blocks`), a shipped page is seeded converted: its body read
 * through the MDX Lens as the conversion reads a file's (laravel-beam-mdx's `MdxFileConverter::convertBody()`, with its
 * safety check). {@see SeedsEntries::seedPage()} uses it, and so does a package seeder that can't take this package as a
 * dependency (laravel-beam-mcp's, by class-string), so both seed the same way.
 */
final class ShippedMdx
{
    /**
     * The blocks to seed, or null to seed the source as before: the host keeps MDX as source, there is no Node where the
     * seed runs, laravel-beam-mdx is absent, or the conversion leaves the body out (a `<Content>`, which no package page
     * has fragments for, or an `export const meta`). The frontmatter stays out of the body; the seeder's own columns are
     * its settings, as when seeding source.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function asBlocks(string $source): ?array
    {
        if (! config('beam.ux.mdx.holds_blocks') || ! class_exists(\Splicewire\Beam\Mdx\Convert\MdxFileConverter::class)) {
            return null;
        }
        [, $body] = \Splicewire\Beam\Mdx\Convert\MdxFileConverter::split($source);
        try {
            $converted = app(\Splicewire\Beam\Mdx\Convert\MdxFileConverter::class)->convertBody($body, []);
        } catch (\Throwable) {
            return null;
        }

        return $converted['problem'] === null && $converted['meta'] === null ? $converted['doc'] : null;
    }
}
