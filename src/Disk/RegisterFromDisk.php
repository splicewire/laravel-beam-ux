<?php

namespace Splicewire\Beam\Ux\Disk;

use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * The **format-aware `register-from-disk`** seam (ADR-0164). The pre-format world hardcoded `.tsx`:
 * file-path derivation was `component.tsx` and disk registration was explicitly "tsx-only". Now the
 * file extension **derives from the entry's `format`**, and registration **widens** to every format the
 * {@see CodecRegistry} knows — so an `.mdx` file on disk is registered exactly as a `.tsx` one is.
 *
 * This is the disk side of the codec dispatch: the same {@link UxFormat} that picks a codec picks an
 * extension, and the registry's registered formats are the set of extensions the scanner recognizes.
 * (Full disk-scan + entry-materialization is S2's `FilePlacement`; S1 establishes the format-awareness.)
 */
class RegisterFromDisk
{
    public function __construct(protected CodecRegistry $codecs) {}

    /** The file extension a given format materializes to (was hardcoded `tsx`; now format-sourced). */
    public function extensionFor(UxFormat|string $format): string
    {
        return $this->codecs->for($format)->extension();
    }

    /**
     * The set of file extensions disk registration recognizes — one per registered codec, no longer
     * the single hardcoded `tsx`. A file whose extension is not in this set is not a BeamUx body.
     *
     * @return array<int, string>
     */
    public function recognizedExtensions(): array
    {
        return array_values(array_map(
            fn (string $format): string => $this->codecs->for($format)->extension(),
            $this->codecs->formats(),
        ));
    }

    /** Is this on-disk filename a body of a format the registry knows how to decode? */
    public function recognizes(string $filename): bool
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        return $ext !== '' && in_array($ext, $this->recognizedExtensions(), true);
    }

    /**
     * The {@see UxFormat} a filename's extension implies, or null when unrecognized — how a disk scan
     * decides which codec decodes a file it found (the widening from tsx-only).
     */
    public function formatForFile(string $filename): ?UxFormat
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        return UxFormat::tryFrom($ext);
    }
}
