<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Ux\Files\CanonicalMdx;
use Splicewire\Beam\Ux\Files\FileConflict;

class CanonicalMdxTest extends TestCase
{
    private Container $prior;

    protected function setUp(): void
    {
        $this->prior = Container::getInstance();
        Container::setInstance(new Container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->prior);
    }

    /** @return array<string, mixed> */
    private function pinned(): array
    {
        return ['frontmatter' => ['title' => 'Help', 'navOrder' => '2'], 'content' => "\n# Published\n\n"];
    }

    public function test_preserves_complete_native_body_and_exact_header_without_normalizing(): void
    {
        $raw = "---\ntitle: Help\nnavOrder: 2\n---\n\n# Edited\n\n";
        $body = (new CanonicalMdx)->decode($raw, $this->pinned());
        self::assertSame(['frontmatter' => ['title' => 'Help', 'navOrder' => '2'], 'content' => "\n# Edited\n\n"], $body);
        self::assertSame("---\ntitle: Help\nnavOrder: 2\n---\n\n# Published\n\n", (new CanonicalMdx)->published($this->pinned()));
    }

    /** @return array<string, array{string}> */
    public static function refusedSources(): array
    {
        $header = "---\ntitle: Help\nnavOrder: 2\n---\n";

        return [
            'quoted value' => ["---\ntitle: \"Help\"\nnavOrder: 2\n---\nBody\n"],
            'comment' => ["---\ntitle: Help\n# ignored\nnavOrder: 2\n---\nBody\n"],
            'CRLF' => ["---\r\ntitle: Help\r\nnavOrder: 2\r\n---\r\nBody\n"],
            'duplicate' => ["---\ntitle: Help\ntitle: Help\nnavOrder: 2\n---\nBody\n"],
            'unknown' => ["---\ntitle: Help\nnavOrder: 2\nlayout: other\n---\nBody\n"],
            'alias' => ["---\ntitle: Help\nnav_order: 2\n---\nBody\n"],
            'incomplete' => ["---\ntitle: Help\nnavOrder: 2\nBody\n"],
            'no header' => ["# Body\n"],
            'blank content' => [$header."\n \t\n"],
            'changed title' => ["---\ntitle: Other\nnavOrder: 2\n---\nBody\n"],
            'changed navigation' => ["---\ntitle: Help\nnavOrder: 3\n---\nBody\n"],
            'header order changed' => ["---\nnavOrder: 2\ntitle: Help\n---\nBody\n"],
            'flow scalar' => ["---\ntitle: [Help]\nnavOrder: 2\n---\nBody\n"],
            'negative order' => ["---\ntitle: Help\nnavOrder: -1\n---\nBody\n"],
            'leading zero' => ["---\ntitle: Help\nnavOrder: 02\n---\nBody\n"],
            'order width' => ["---\ntitle: Help\nnavOrder: 1234567\n---\nBody\n"],
            'title width' => ["---\ntitle: ".str_repeat('a', 121)."\nnavOrder: 2\n---\nBody\n"],
            'invalid UTF8' => [$header."\xff"],
            'oversize' => [$header.str_repeat('a', CanonicalMdx::LIMIT)],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedSources')]
    public function test_refuses_noncanonical_or_header_changing_inputs(string $raw): void
    {
        $this->expectException(FileConflict::class);
        (new CanonicalMdx)->decode($raw, $this->pinned());
    }

    public function test_rejects_malformed_pinned_shapes_instead_of_dropping_fields(): void
    {
        $valid = $this->pinned();
        $cases = [
            $valid + ['extra' => 'would be discarded'],
            ['frontmatter' => $valid['frontmatter']],
            ['frontmatter' => [], 'content' => 'Body'],
            ['frontmatter' => ['title' => 'Help', 'navOrder' => 2], 'content' => 'Body'],
            ['frontmatter' => ['title' => 'Help', 'navOrder' => '2', 'realm' => 'other'], 'content' => 'Body'],
            ['frontmatter' => $valid['frontmatter'], 'content' => ['not source']],
            ['frontmatter' => $valid['frontmatter'], 'content' => ''],
        ];
        foreach ($cases as $body) {
            try {
                (new CanonicalMdx)->published($body);
                self::fail('Malformed published body was accepted');
            } catch (FileConflict $failure) {
                self::assertSame(422, $failure->status);
            }
        }
    }

    public function test_lossless_admission_does_not_pretend_to_compile_mdx(): void
    {
        $body = (new CanonicalMdx)->decode("---\ntitle: Help\nnavOrder: 2\n---\n<Unclosed\n", $this->pinned());
        self::assertSame("<Unclosed\n", $body['content']);
    }
}
