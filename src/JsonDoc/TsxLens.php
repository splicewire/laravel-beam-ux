<?php

namespace Splicewire\Beam\Ux\JsonDoc;

/**
 * ADR-0228 d9 — the TSX Lens: lowers TSX/source into an id-less JsonDoc node array. The AST lens is the
 * source of truth, so this is a Node concern ({@see NodeTsxLens} shells out to it); the host binds the
 * implementation. The returned doc carries no ids — {@see JsonDocIdRematcher} reattaches the Working
 * Head's stable ids by content afterward.
 */
interface TsxLens
{
    /**
     * @return array<int, array<string, mixed>>  an id-less JsonDoc node array
     */
    public function lower(string $source): array;
}
