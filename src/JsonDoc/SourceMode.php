<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** ADR JS0006 d4: conversion of the Local Session, never a storage operation. */
final class SourceMode
{
    public static function assertAvailable(BeamUxEntry $entry): void
    {
        if (! app(EntryResidency::class)->isCentralResident($entry)
            || $entry->bodyKind() !== BodyKind::Blocks || $entry->format?->value !== 'tsx') {
            throw ValidationException::withMessages(['source' => 'Source Mode requires a central TSX blocks entry; Hosted sites have no Source Mode.']);
        }
    }
}
