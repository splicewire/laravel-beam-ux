<?php

namespace Splicewire\Beam\Ux\Disk;

use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Type\UxType;

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

    /**
     * Reverse S2's {@see DefaultPlacement} — derive the authoring envelope
     * a disk-relative path implies (`{namespace dots→slashes}/{type}/{slug}.{ext}`):
     *
     *  - **format** ← the extension (an unrecognized extension is not a body → null envelope).
     *  - **type** ← the path segment IMMEDIATELY before the filename, when it names a {@see UxType}
     *    (`.../component/hero.tsx` → `component`); a path whose last dir is not a type keeps `type = null`
     *    (the operator/host decides), and the whole dir chain becomes namespace.
     *  - **namespace** ← the remaining leading segments joined by `.` (`kit/hero/component/hero.tsx` →
     *    `kit.hero`); empty when the file sits at the type root or the scan root.
     *  - **slug** ← the filename without its extension.
     *
     * @return array{slug: string, type: ?string, namespace: ?string, format: string}|null
     *                                                                                     null when the extension is not a recognized body format.
     */
    public function envelopeForPath(string $relativePath): ?array
    {
        $format = $this->formatForFile($relativePath);
        if ($format === null) {
            return null;
        }

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $slug = pathinfo($relativePath, PATHINFO_FILENAME);

        $dir = trim((string) pathinfo($relativePath, PATHINFO_DIRNAME), '/.');
        $segments = $dir === '' ? [] : explode('/', $dir);

        $type = null;
        if ($segments !== [] && UxType::tryFrom(end($segments)) !== null) {
            $type = array_pop($segments);
        }

        $namespace = $segments === [] ? null : implode('.', $segments);

        return [
            'slug' => $slug,
            'type' => $type,
            'namespace' => $namespace,
            'format' => $format->value,
        ];
    }
}
