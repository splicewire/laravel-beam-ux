<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Mdx\Lens\MdxLens;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Codec\JsonDocPrinter;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR JS0006 d4: conversion of the Local Session, never a storage operation. Source Mode shows a central blocks
 * entry in its File Language: `tsx` through the TSX printer, and an MDX page whose host holds MDX as blocks
 * (ADR-0233 d1) through the MDX Lens's printer.
 */
final class SourceMode
{
    public static function assertAvailable(BeamUxEntry $entry): void
    {
        if (! app(EntryResidency::class)->isCentralResident($entry)
            || $entry->bodyKind() !== BodyKind::Blocks || ! in_array($entry->format?->value, [UxFormat::Tsx->value, UxFormat::Mdx->value], true)) {
            throw ValidationException::withMessages(['source' => 'Source Mode requires a central TSX or MDX blocks entry; Hosted sites have no Source Mode.']);
        }
    }

    /**
     * Print a Local Session's blocks in the entry's File Language.
     *
     * @param  array<int, array<string, mixed>>  $body
     */
    public static function print(BeamUxEntry $entry, array $body): string
    {
        return $entry->format === UxFormat::Mdx ? app(MdxLens::class)->print($body) : JsonDocPrinter::print($body);
    }
}
