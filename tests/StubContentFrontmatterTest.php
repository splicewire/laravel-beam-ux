<?php

namespace Splicewire\Beam\Ux\Tests;

use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Ux\Seed\StubContent;

/**
 * `StubContent` reads through the shared grammar (frontmatter-declaration-seam ticket 04) and takes
 * the CANONICAL fields, because {@see StubContent::columns()} maps onto entry columns.
 *
 * The camel case here is not hypothetical: a host publishes these stubs
 * (`vendor:publish --tag=beam-ux-docs`) and edits them, and the estate's own content is authored
 * `navOrder:` in 23 files. Before the collapse such a stub parsed cleanly and was then dropped —
 * a silent no-op with no error anywhere.
 */
class StubContentFrontmatterTest extends TestCase
{
    #[Test]
    public function a_camel_authored_stub_lands_in_the_columns(): void
    {
        $stub = StubContent::parse("---\ntitle: A page\nnavOrder: 4\n---\n# Body\n");

        $this->assertSame('A page', $stub->columns()['title'] ?? null);
        $this->assertSame(4, $stub->columns()['nav_order'] ?? null);
    }

    #[Test]
    public function a_snake_authored_stub_is_unchanged(): void
    {
        $stub = StubContent::parse("---\ntitle: A page\nnav_order: 4\n---\n# Body\n");

        $this->assertSame(4, $stub->columns()['nav_order'] ?? null);
    }

    #[Test]
    public function the_body_keeps_the_authored_source_verbatim(): void
    {
        // Canonicalization touches the COLUMN projection, never the text an author wrote — the body
        // is what the disk mirror projects back out.
        $raw = "---\ntitle: A page\nnavOrder: 4\n---\n# Body\n";

        $this->assertSame($raw, StubContent::parse($raw)->body);
    }
}
