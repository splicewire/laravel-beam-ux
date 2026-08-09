<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Disk\RawMdxReader;

/**
 * The frontmatter-stripped raw-`.mdx` reader (editor-promotion 05). Seeds an mdxeditor buffer with the
 * existing on-disk copy — the vite `@mdx-js` plugin compiles `.mdx` at build time, so the client can never
 * `?raw`-load the original source; the read happens server-side. Root is `beam.ux.content_path`-configurable
 * and a missing file degrades to null (the caller renders its default).
 */
class RawMdxReaderTest extends TestCase
{
    private string $root = 'raw-mdx-fixtures';

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(base_path($this->root.'/legal'), 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->root.'/legal/privacy.mdx'));
        @rmdir(base_path($this->root.'/legal'));
        @rmdir(base_path($this->root));

        parent::tearDown();
    }

    private function reader(): RawMdxReader
    {
        return new RawMdxReader($this->root);
    }

    public function test_it_strips_the_leading_frontmatter_block_and_trims(): void
    {
        file_put_contents(
            base_path($this->root.'/legal/privacy.mdx'),
            "---\ntitle: Privacy\nrealm: site\n---\n\n# Privacy Policy\n\nWe respect your privacy.\n",
        );

        $raw = $this->reader()->read('legal/privacy');

        $this->assertSame("# Privacy Policy\n\nWe respect your privacy.", $raw);
        $this->assertStringNotContainsString('title: Privacy', (string) $raw);
        $this->assertStringNotContainsString('---', (string) $raw);
    }

    public function test_a_file_with_no_frontmatter_is_returned_verbatim_trimmed(): void
    {
        file_put_contents(
            base_path($this->root.'/legal/privacy.mdx'),
            "\n# Just Content\n\nNo frontmatter here.\n\n",
        );

        $this->assertSame("# Just Content\n\nNo frontmatter here.", $this->reader()->read('legal/privacy'));
    }

    public function test_a_missing_file_returns_null(): void
    {
        $this->assertNull($this->reader()->read('legal/does-not-exist'));
    }

    public function test_the_content_root_comes_from_config(): void
    {
        // The provider binds the reader against `beam.ux.content_path`; forget the resolved singleton so a
        // fresh make picks up an overridden root.
        config()->set('beam.ux.content_path', $this->root);
        $this->app->forgetInstance(RawMdxReader::class);

        /** @var RawMdxReader $reader */
        $reader = $this->app->make(RawMdxReader::class);

        file_put_contents(
            base_path($this->root.'/legal/privacy.mdx'),
            "---\ntitle: X\n---\nHello.\n",
        );

        $this->assertSame('Hello.', $reader->read('legal/privacy'));
    }
}
