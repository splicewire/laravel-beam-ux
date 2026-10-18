<?php

namespace Splicewire\Beam\Ux\JsonDoc;

/**
 * ADR-0228 d9 — lowers TSX/source to a JsonDoc body AND reattaches the Working Head's stable ids, so a
 * block keeps its UUIDv7 id across a source round trip (the lasting fix for DF-15). The three entry
 * points the ADR names — registration from disk, the Files action, and a source write of a Blocks-kind
 * entry — all go through this: run the {@see TsxLens} in Node, then {@see JsonDocIdRematcher} against the
 * entry's current stored body. The result feeds the ordinary save strictness ({@see BodyNodeDiff} /
 * ProfileLegality), which then refuses only the nodes that actually changed.
 */
final class SourceToBlocks
{
    public function __construct(
        private TsxLens $lens,
        private JsonDocIdRematcher $rematcher = new JsonDocIdRematcher,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $workingHead  the entry's current stored JsonDoc body
     * @return array<int, array<string, mixed>> the lowered JsonDoc with stable ids reattached/minted
     */
    public function convert(string $source, array $workingHead, ?array $profile = null): array
    {
        $doc = $profile !== null && $this->lens instanceof ProfiledTsxLens
            ? $this->lens->lowerWithProfile($source, $profile)
            : $this->lens->lower($source);

        return $this->rematcher->rematch($workingHead, $doc);
    }
}
