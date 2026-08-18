<?php

namespace Splicewire\Beam\Ux\Codegen;

use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\UxFormatCase;

/**
 * Writes a `UxFormat`-shaped enum SOURCE reflecting {@see CodecRegistry}'s CURRENTLY registered
 * formats — genuinely live, not a fixed snapshot: whatever a host's own providers have `register()`ed
 * by the time this runs (the tsx/mdx/css seed set PLUS anything a host added) is what gets written.
 *
 * The generated enum `implements` {@see UxFormatCase}, same as the hand-written
 * {@see \Splicewire\Beam\Ux\Format\UxFormat} does — but per that interface's whole point, this class
 * being incomplete or out of date NEVER blocks a host from registering a codec for a format this
 * hasn't caught up to yet. Regenerating (calling this again) is purely a convenience refresh for
 * autocomplete/`Rule::enum()`, never a prerequisite.
 *
 * Not a framework command by itself on purpose — a host wires the call (an artisan command, a deploy
 * step, a test) at whatever cadence suits it. This package takes no position on WHEN to regenerate.
 */
class UxFormatWriter
{
    public function __construct(private CodecRegistry $codecs) {}

    /** The enum's source text — write it wherever the caller wants (`file_put_contents`, a Filesystem
     * disk, …); this class has no opinion on storage, only on what the source says. */
    public function source(string $namespace = 'App\\Data', string $name = 'UxFormat'): string
    {
        return EnumWriter::write(
            $namespace,
            $name,
            $this->codecs->formats(),
            implements: [UxFormatCase::class],
            doc: 'Generated from CodecRegistry — every BodyCodec format registered at boot. '.
                'Regenerating never blocks registering a NEW one (see UxFormatCase); this is a '.
                'convenience snapshot, not a ceiling.',
        );
    }
}
