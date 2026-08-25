<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresCodecs
{
    /**
     * The {@see CodecRegistry} — the format→codec dispatch seam (ADR-0164). Bound as a singleton
     * seeded with the TSX + MDX + CSS codecs. beam-ux owns the dispatch; the MDX codec's
     * engine is the sibling `laravel-beam-mdx` arm, folded in (not deleted) via {@see MdxBodyCodec}.
     * {@see CssBodyCodec} is the `UxType::Theme` entry's default (`BeamUxEntry::defaultFormatFor()`) —
     * OTB, not a per-host add-on, since `Theme` is itself a package-level structural type. A host can
     * still `register()` further codecs on the same singleton for formats beyond this seed set.
     */
    #[Chained('register', order: 10)]
    protected function registerCodecs(): void
    {
        $this->app->singleton(CodecRegistry::class, function () {
            return (new CodecRegistry)
                ->register(new TsxBodyCodec)
                ->register(new MdxBodyCodec)
                ->register(new CssBodyCodec);
        });
    }
}
