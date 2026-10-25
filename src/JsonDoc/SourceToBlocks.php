<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Mdx\Lens\MdxLens;
use Splicewire\Beam\Ux\Codec\UxFormatCase;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * ADR-0228 d9 — lowers TSX/source to a JsonDoc body AND reattaches the Working Head's stable ids, so a
 * block keeps its UUIDv7 id across a source round trip (the lasting fix for DF-15). The three entry
 * points the ADR names — registration from disk, the Files action, and a source write of a Blocks-kind
 * entry — all go through this: run the {@see TsxLens} in Node, then {@see JsonDocIdRematcher} against the
 * entry's current stored body. The result feeds the ordinary save strictness ({@see BodyNodeDiff} /
 * ProfileLegality), which then refuses only the nodes that actually changed.
 *
 * ADR-0233 d2 — an MDX entry's source reads through the MDX Lens instead (`splicewire/laravel-beam-mdx`,
 * also Node), with the same id rematch. Its source is the page body alone: frontmatter is the page's
 * settings (d3), so a source that carries a frontmatter block, or schemastud's `export const meta`, is
 * refused rather than dropped.
 */
final class SourceToBlocks
{
    public function __construct(
        private TsxLens $lens,
        private JsonDocIdRematcher $rematcher = new JsonDocIdRematcher,
        // Resolved when an MDX source is read, so a TSX-only caller never needs the MDX Lens bound.
        private ?MdxLens $mdx = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $workingHead  the entry's current stored JsonDoc body
     * @param  array<string, mixed>|null  $profile  the resolved Manifest Profile the source is read against
     * @return array<int, array<string, mixed>> the lowered JsonDoc with stable ids reattached/minted
     */
    public function convert(string $source, array $workingHead, ?array $profile = null, UxFormatCase|string|null $format = null): array
    {
        $doc = self::isMdx($format) ? $this->readMdx($source, $profile) : (
            $profile !== null && $this->lens instanceof ProfiledTsxLens
                ? $this->lens->lowerWithProfile($source, $profile)
                : $this->lens->lower($source)
        );

        return $this->rematcher->rematch($workingHead, $doc);
    }

    /**
     * @param  array<string, mixed>|null  $profile
     * @return array<int, array<string, mixed>>
     */
    private function readMdx(string $source, ?array $profile): array
    {
        if (preg_match('/\A(?:\xEF\xBB\xBF)?---\r?\n/', $source) === 1) {
            throw ValidationException::withMessages(['source' => 'An MDX page\'s frontmatter is its page settings, not its body (ADR-0233 d3). Edit those in page settings and write the body alone.']);
        }

        $read = ($this->mdx ?? app(MdxLens::class))->read($source, $profile === null ? [] : ['profile' => $profile]);
        if ($read['meta'] !== null) {
            throw ValidationException::withMessages(['source' => '`export const meta` becomes frontmatter when the host converts its MDX (ADR-0233 d9); a blocks page body holds no code.']);
        }

        return $read['doc'];
    }

    private static function isMdx(UxFormatCase|string|null $format): bool
    {
        $value = $format instanceof UxFormatCase ? $format->value : $format;

        return $value === UxFormat::Mdx->value;
    }
}
