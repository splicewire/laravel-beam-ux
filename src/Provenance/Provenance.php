<?php

namespace Splicewire\Beam\Ux\Provenance;

use Illuminate\Support\Facades\Schema;

/**
 * Entry provenance (DOCS-06 / DM2, ADR-0215).
 *
 * A row's `origin` records WHERE its body came from, and `asserted_hash` is the hash of the
 * title+body that origin last wrote. Together they let a reader tell a pristine row (still equal to
 * what its origin asserted) from an edited one — the precondition for DOC-5 precedence and the
 * `docs.diverged` audit. This class is the one place that spells the origin vocabulary, the
 * precedence order, and the hash, so the seed path, the import path, and the audit agree.
 *
 * This class is pure: it only spells the origin vocabulary, the precedence order and the hash, and
 * mutates nothing. The pristine re-assert that acts on them lives in {@see Reasserter}; the
 * `docs.diverged` audit that merely reports a diverged row is genuinely report-only.
 */
final class Provenance
{
    /** A row authored in the editor. Never touched by seed or import. */
    public const CMS = 'cms';

    /** A host file, e.g. `disk:beam/docs/index.mdx`. */
    public const DISK_PREFIX = 'disk:';

    /** A package stub, e.g. `package:splicewire/laravel-beam-docs`. */
    public const PACKAGE_PREFIX = 'package:';

    public static function disk(string $relative): string
    {
        return self::DISK_PREFIX.ltrim($relative, '/');
    }

    public static function package(string $vendorName): string
    {
        return self::PACKAGE_PREFIX.$vendorName;
    }

    /**
     * DOC-5 precedence (strongest first): a CMS-edited row, then a host disk file, then a package
     * stub. A higher rank wins the `(namespace, slug)` coordinate; equal ranks that disagree are a
     * doctor collision, not a silent winner. Edited state only lifts a `cms` row — a package/disk
     * origin is ranked by where it came from, not by whether the host later edited it (that edit is
     * what `docs.diverged` reports).
     */
    public static function rank(?string $origin): int
    {
        return match (true) {
            $origin === self::CMS => 3,
            $origin !== null && str_starts_with($origin, self::DISK_PREFIX) => 2,
            $origin !== null && str_starts_with($origin, self::PACKAGE_PREFIX) => 1,
            default => 0,
        };
    }

    /** True when `$candidate` origin outranks the `$incumbent` already at a coordinate. */
    public static function outranks(?string $candidate, ?string $incumbent): bool
    {
        return self::rank($candidate) > self::rank($incumbent);
    }

    /**
     * The hash an origin asserts for a row: title + body, normalized to LF. Stable across the seed
     * and import paths and the audit's recompute, so a byte-identical re-assert produces the same
     * hash and a real edit produces a different one.
     */
    public static function hash(?string $title, string $body): string
    {
        $normalized = str_replace("\r\n", "\n", (string) $title."\n".$body);

        return hash('sha256', $normalized);
    }

    /**
     * The provenance columns to merge into a create — but ONLY when the schema carries them, so a
     * host that has not run the DOCS-06 migration keeps working (the package's hasColumn-tolerance
     * convention). Returns `[]` otherwise, and the create is unchanged.
     *
     * @return array{origin?: string, asserted_hash?: string}
     */
    public static function stamp(string $origin, ?string $title, string $body): array
    {
        if (! Schema::hasColumn('beam_ux_entries', 'origin')) {
            return [];
        }

        return [
            'origin' => $origin,
            'asserted_hash' => self::hash($title, $body),
        ];
    }
}
