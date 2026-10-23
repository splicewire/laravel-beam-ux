<?php

namespace Splicewire\Beam\Ux\Files;

use Splicewire\Beam\Ux\Codec\MdxBodyCodec;

/** Deliberately narrow body-only import policy, not native YAML/MDX or code validation. */
class CanonicalMdx
{
    public const LIMIT = 1048576;

    /** @param array<string, mixed> $published
     * @return array<string, mixed>
     */
    public function decode(string $bytes, array $published): array
    {
        $pinned = $this->published($published);
        $candidate = $this->source($bytes);
        $candidateHeader = substr($bytes, 0, strlen($bytes) - strlen($candidate['content']));
        $pinnedHeader = substr($pinned, 0, strlen($pinned) - strlen($published['content']));
        if ($candidate['frontmatter'] !== $published['frontmatter'] || $candidateHeader !== $pinnedHeader) {
            throw new FileConflict('invalid', 'Only the MDX content may change. Preserve the exact published title and navOrder header.', 422);
        }

        return $candidate;
    }

    /** Verify the COMPLETE pinned representation before decoding can discard unknown fields.
     * @param  array<string, mixed>  $body
     *
     * @phpstan-assert array{frontmatter: array<string, string>, content: string} $body
     */
    public function published(array $body): string
    {
        $this->shape($body);
        $raw = (new MdxBodyCodec)->decode($body);
        $parsed = $this->source($raw);
        if ($parsed['frontmatter'] !== $body['frontmatter'] || $parsed['content'] !== $body['content']) {
            throw new FileConflict('unsupported', 'The complete published MDX body cannot be verified.', 422);
        }

        return $raw;
    }

    /** @return array{frontmatter: array<string, string>, content: string} */
    private function source(string $bytes): array
    {
        if (strlen($bytes) > self::LIMIT || ! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\r") || ! str_starts_with($bytes, "---\n")) {
            throw new FileConflict('invalid', 'MDX import requires bounded UTF-8 with a complete canonical LF header.', 422);
        }
        $codec = new MdxBodyCodec;
        $body = $codec->encode($bytes);
        $this->shape($body);
        $once = $codec->decode($body);
        if ($once !== $bytes) {
            throw new FileConflict('invalid', 'MDX header normalization is not imported. Preserve the exact canonical title and navOrder header.', 422);
        }
        $again = $codec->encode($once);
        if (($again['content'] ?? null) !== $body['content'] || $codec->decode($again) !== $once) {
            throw new FileConflict('invalid', 'The MDX body is not a lossless stable representation.', 422);
        }
        $header = substr($bytes, 0, strlen($bytes) - strlen($body['content']));
        if (! str_ends_with($header, "\n---\n")) {
            throw new FileConflict('invalid', 'A complete canonical MDX header is required.', 422);
        }

        /** @var array{frontmatter: array<string, string>, content: string} $body */
        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @phpstan-assert array{frontmatter: array<string, string>, content: string} $body
     */
    private function shape(array $body): void
    {
        $keys = array_keys($body);
        sort($keys);
        $frontmatter = $body['frontmatter'] ?? null;
        if ($keys !== ['content', 'frontmatter'] || ! is_array($frontmatter) || ! is_string($body['content']) || trim($body['content']) === '') {
            throw new FileConflict('invalid', 'MDX requires exactly a complete frontmatter/content body and nonblank content.', 422);
        }
        $headerKeys = array_keys($frontmatter);
        sort($headerKeys);
        if ($headerKeys !== ['navOrder', 'title'] || ! is_string($frontmatter['title']) || ! is_string($frontmatter['navOrder'])
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,119}$/D', $frontmatter['title'])
            || ! preg_match('/^(?:0|[1-9][0-9]{0,5})$/D', $frontmatter['navOrder'])) {
            throw new FileConflict('invalid', 'MDX import supports only a plain title and canonical nonnegative navOrder, both unchanged from publication.', 422);
        }
    }
}
